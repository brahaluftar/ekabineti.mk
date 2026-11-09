<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\Money;
use EKabineti\Service\BudgetExecution;
use EKabineti\Service\EvidenceAccess;
use EKabineti\Service\GeoReference;
use EKabineti\Core\HttpException;
use EKabineti\Core\Request;
use EKabineti\Service\ImportWorkflow;
use EKabineti\Service\InspectionReporting;
use EKabineti\Service\LegacySpreadsheetException;
use EKabineti\Service\LoginThrottle;
use EKabineti\Service\PrivateAttachmentStorage;
use EKabineti\Service\UserPreferences;

final class WriteController extends Controller
{
    public function inspectionReport(): never
    {
        $reporting=new InspectionReporting($this->db);$directorate=$reporting->directorate();if(!$directorate)throw new HttpException(503,'Секторот за инспекциски надзор не е пронајден.');$directorateId=(int)$directorate['id'];if(!$this->scope->canSubmitInspectionReporting($this->userId(),$directorateId))throw new HttpException(403,'Само известувачите на Секторот за инспекциски надзор можат да зачуваат извештај.');
        $start=trim((string)$this->request->input('period_start',''));$end=trim((string)$this->request->input('period_end',''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$end<$start)throw new HttpException(422,'Периодот на известување не е валиден.');$values=[];foreach(array_keys(InspectionReporting::METRICS) as$key){$raw=trim((string)$this->request->input($key,''));if(!preg_match('/^\d{1,10}$/',$raw)||(float)$raw>2147483647)throw new HttpException(422,'Нумеричкиот показател не е валиден: '.$key);$values[$key]=(int)$raw;}$plan=trim((string)$this->request->input('mobility_annual_plan',''));$notes=trim((string)$this->request->input('notes',''));if($plan===''||mb_strlen($plan)>4000||mb_strlen($notes)>4000)throw new HttpException(422,'Годишниот план е задолжителен, а текстовите можат да имаат до 4000 знаци.');
        $columns=array_keys(InspectionReporting::METRICS);$params=['directorate'=>$directorateId,'start'=>$start,'end'=>$end,'plan'=>$plan,'notes'=>$notes?:null,'user'=>$this->userId()]+$values;$sql='INSERT INTO inspection_reports(directorate_id,period_start,period_end,'.implode(',',$columns).',mobility_annual_plan,notes,submitted_by) VALUES(:directorate,:start,:end,'.implode(',',array_map(static fn($key)=>':'.$key,$columns)).',:plan,:notes,:user)';try{$id=$this->db->insertId($sql,$params);}catch(\Throwable $e){if(str_contains($e->getMessage(),'UQ_inspection_reports_period'))throw new HttpException(422,'За овој период веќе постои извештај.');throw $e;}$this->audit->record($this->userId(),'inspection_report.created','InspectionReport',$id,[],['directorate_id'=>$directorateId,'period_start'=>$start,'period_end'=>$end]);$this->redirect('/reports/inspection','Извештајот на Инспекцијата е зачуван.');
    }
    public function aiTechnicalProposal(): never
    {
        $request=$this->requireField('request_text',2000);$openAi=\EKabineti\Service\OpenAIConfig::fromApplication($this->config);
        $assistant=new \EKabineti\Service\AiTechnicalAssistant($this->db,new \EKabineti\Service\OpenAIResponsesClient($openAi),$this->logger,APP_ROOT.'/storage');
        $id=$assistant->propose($this->userId(),$request);$this->audit->record($this->userId(),'ai_technical.proposed','AiTechnicalProposal',$id,[],['request'=>$request]);
        $this->redirect('/ai-technical-assistant','Технички предлог #'.$id.' е создаден и чека одобрување.');
    }

    public function aiTechnicalApprove(int $id): never
    {
        $reason=$this->requireField('review_reason',2000);$openAi=\EKabineti\Service\OpenAIConfig::fromApplication($this->config);
        $assistant=new \EKabineti\Service\AiTechnicalAssistant($this->db,new \EKabineti\Service\OpenAIResponsesClient($openAi),$this->logger,APP_ROOT.'/storage');
        $executor=new \EKabineti\Service\AiInterventionExecutor($this->db,$this->notifications,$this->logger,$this->config,APP_ROOT.'/storage');
        $result=$assistant->approveAndExecute($id,$this->userId(),$reason,$executor);$this->audit->record($this->userId(),'ai_technical.approved_executed','AiTechnicalProposal',$id,['status'=>'proposed'],['status'=>'executed','result_status'=>$result['status']??null],$reason);
        $this->redirect('/ai-technical-assistant','Технички предлог #'.$id.' е одобрен и дозволеното дејство е извршено.');
    }

    public function aiTechnicalReject(int $id): never
    {
        $reason=$this->requireField('review_reason',2000);$openAi=\EKabineti\Service\OpenAIConfig::fromApplication($this->config);
        $assistant=new \EKabineti\Service\AiTechnicalAssistant($this->db,new \EKabineti\Service\OpenAIResponsesClient($openAi),$this->logger,APP_ROOT.'/storage');
        $assistant->reject($id,$this->userId(),$reason);$this->audit->record($this->userId(),'ai_technical.rejected','AiTechnicalProposal',$id,['status'=>'proposed'],['status'=>'rejected'],$reason);
        $this->redirect('/ai-technical-assistant','Технички предлог #'.$id.' е одбиен.');
    }

    public function aiReport(): never
    {
        $request=$this->requireField('request_text',2000);$openAi=\EKabineti\Service\OpenAIConfig::fromApplication($this->config);
        $agent=new \EKabineti\Service\AiReportingAgent($this->db,$this->scope,new \EKabineti\Service\OpenAIResponsesClient($openAi));
        $id=$agent->generate($this->userId(),$request);$this->audit->record($this->userId(),'ai_report.generated','AiReport',$id,[],['request'=>$request]);
        $this->redirect('/ai-reporting?report='.$id,'AI извештај #'.$id.' е создаден.');
    }

    public function notificationsRead(): never
    {
        $id=trim((string)$this->request->input('id',''));
        if($id!==''&&!preg_match('/^[0-9a-f-]{36}$/i',$id))throw new HttpException(422,'Известувањето не е валидно.');
        if($id==='')$this->db->execute("UPDATE notifications SET read_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE recipient_id=:u AND channel='in_app' AND read_at IS NULL",['u'=>$this->userId()]);
        else$this->db->execute("UPDATE notifications SET read_at=COALESCE(read_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE id=:id AND recipient_id=:u AND channel='in_app'",['id'=>$id,'u'=>$this->userId()]);
        $this->redirect('/notifications',$id===''?'Сите известувања се означени како прочитани.':'Известувањето е означено како прочитано.');
    }

    public function login(): never
    {
        $login=$this->requireField('login',255);$password=$this->requireField('password');
        $throttle=new LoginThrottle($this->db);$ip=$this->request->ip()??'unknown';$buckets=['login:ip:'.$ip,'login:id:'.mb_strtolower(trim($login))];
        $throttle->assertAllowed(['login:id:'.mb_strtolower(trim($login))],5,'Премногу неуспешни обиди за оваа сметка. Обидете се по 15 минути.');
        $throttle->assertAllowed(['login:ip:'.$ip],30,'Премногу обиди од оваа адреса. Обидете се по 15 минути.');
        if(!$this->auth->attempt($login,$password)){$throttle->hit($buckets,900);$this->audit->record(null,'user.login_failed','User',null,[],['login_hash'=>hash('sha256',mb_strtolower(trim($login)))]);throw new HttpException(422,'Акредитивите не се точни или сметката не е активна.');}
        $throttle->clear(['login:id:'.mb_strtolower(trim($login))]);$this->audit->record($this->userId(),'user.login','User',$this->userId());
        $this->redirect('/','Добредојдовте.');
    }
    public function logout(): never { $this->audit->record($this->userId(),'user.logout','User',$this->userId());$this->auth->logout();header('Location: /login',true,303);exit; }
    public function language(): never
    {
        $locale=$this->requireField('locale',2);if(!in_array($locale,['mk','en'],true))throw new HttpException(422,'Јазикот не е валиден.');
        $this->db->execute('UPDATE users SET locale=:locale,updated_at=SYSUTCDATETIME() WHERE id=:id',['locale'=>$locale,'id'=>$this->userId()]);
        $_SESSION['_locale']=$locale;$this->audit->record($this->userId(),'user.locale_changed','User',$this->userId(),[],['locale'=>$locale]);
        $this->redirect($this->request->safeReferer('/'),$locale==='en'?'Language changed to English.':'Јазикот е променет на македонски.');
    }

    public function preferences(): never
    {
        $mode=(string)$this->request->input('notification_email_mode','');
        if(!in_array($mode,['immediate','daily','weekly','time_window'],true))throw new HttpException(422,'Начинот на известување не е валиден.');
        $digestTime=(string)$this->request->input('notification_digest_time','09:00');$weekday=$this->intField('notification_digest_weekday',1,7);
        $windowStart=trim((string)$this->request->input('notification_window_start',''));$windowEnd=trim((string)$this->request->input('notification_window_end',''));
        foreach(['Време на сумаријата'=>$digestTime,'Почеток на периодот'=>$windowStart,'Крај на периодот'=>$windowEnd] as$label=>$time)if($time!==''&&(!$this->validTime($time)))throw new HttpException(422,$label.' не е валиден.');
        if($mode==='time_window'&&($windowStart===''||$windowEnd===''))throw new HttpException(422,'Дневниот период мора да има почеток и крај.');
        if($windowStart!==''&&$windowEnd!==''&&$windowEnd<=$windowStart)throw new HttpException(422,'Крајот на периодот мора да биде по почетокот.');
        $allowed=UserPreferences::DEFAULT_WIDGETS;$posted=$this->request->input('dashboard_widgets',[]);$widgets=is_array($posted)?array_values(array_unique(array_intersect(array_map('strval',$posted),$allowed))):[];
        (new UserPreferences($this->db))->save($this->userId(),['notification_email_mode'=>$mode,'notification_digest_time'=>$digestTime,'notification_digest_weekday'=>$weekday,'notification_window_start'=>$windowStart!==''?$windowStart:null,'notification_window_end'=>$windowEnd!==''?$windowEnd:null,'dashboard_widgets'=>$widgets]);
        $this->audit->record($this->userId(),'user.preferences_updated','User',$this->userId(),[],['notification_email_mode'=>$mode,'dashboard_widgets'=>$widgets]);
        $this->redirect('/profile/preferences','Поставките се успешно зачувани.');
    }

    private function validTime(string $value): bool
    {
        if(!preg_match('/^\d{2}:\d{2}$/',$value))return false;
        [$hour,$minute]=array_map('intval',explode(':',$value));return $hour>=0&&$hour<=23&&$minute>=0&&$minute<=59;
    }

    public function password(): never
    {
        $current=$this->requireField('current_password',4096);$password=$this->requireField('new_password',4096);$confirmation=$this->requireField('new_password_confirmation',4096);unset($_POST['current_password'],$_POST['new_password'],$_POST['new_password_confirmation']);$userId=$this->userId();
        $stored=$this->db->one('SELECT id,password FROM users WHERE id=:id AND status=:status',['id'=>$userId,'status'=>'active']);
        if(!$stored||!password_verify($current,(string)$stored['password'])){$this->audit->record($userId,'user.password_change_failed','User',$userId,[],[],'Тековната лозинка не се совпаѓа');throw new HttpException(422,'Тековната лозинка не е точна.');}
        if($password!==$confirmation)throw new HttpException(422,'Потврдата на новата лозинка не се совпаѓа.');
        self::assertPasswordPolicy($password);
        if(password_verify($password,(string)$stored['password']))throw new HttpException(422,'Новата лозинка мора да се разликува од тековната лозинка.');
        $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);if($hash===false)throw new \RuntimeException('Генерирањето на хашот на лозинката не успеа.');
        $this->db->transaction(function()use($userId,$hash){$this->db->execute('UPDATE users SET password=:password,remember_token=NULL,updated_at=SYSUTCDATETIME() WHERE id=:id',['password'=>$hash,'id'=>$userId]);$this->audit->record($userId,'user.password_changed','User',$userId,[],['session_regenerated'=>true]);});
        $current=$password=$confirmation=$hash='';$this->auth->revokeSessions($userId);
        $this->notifications->notify($userId,'password_changed','Лозинката за е-Кабинет е променета · '.gmdate('Y-m-d H:i:s').' UTC',['user_id'=>$userId,'body'=>'Лозинката на вашата сметка во е-Кабинет е променета. Ако не сте го извршиле вие ова дејство, веднаш контактирајте го Администраторот на системот.']);
        $this->redirect('/profile/security','Лозинката е успешно променета.');
    }

    public function register(): never
    {
        if(!$this->request->input('accept_terms'))throw new HttpException(422,'Мора да ги прифатите условите и политиката за приватност.');
        [$name,$username,$email,$passwordHash]=$this->userIdentity();
        $this->assertUniqueIdentity($username,$email);
        $id=$this->db->insertId('INSERT INTO users(name,username,email,email_verified_at,password,locale,status,last_login_at,remember_token,created_at,updated_at) VALUES(:name,:username,:email,NULL,:password,:locale,:status,NULL,NULL,SYSUTCDATETIME(),SYSUTCDATETIME())',['name'=>$name,'username'=>$username,'email'=>$email,'password'=>$passwordHash,'locale'=>'mk','status'=>'disabled']);
        $this->audit->record(null,'user.self_registered','User',$id,[],['username'=>$username,'email'=>$email,'status'=>'disabled'],'Чека одобрување од администраторот');
        $subject='Нова регистрација во е-Кабинет · #'.$id;
        $admins=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE COALESCE(r.base_role_code,r.code)='system_admin' AND u.status='active' AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");
        foreach($admins as$admin)$this->notifications->notify((int)$admin['id'],'user_self_registered',$subject,['user_id'=>$id,'body'=>'Корисник '.$name.' (@'.$username.') побара регистрација. Отворете го менито Корисници за да му доделите улога и да го активирате.']);
        $this->notifications->notify($id,'registration_received','Барањето за регистрација е примено · #'.$id,['user_id'=>$id,'body'=>'Вашето барање за е-Кабинет е примено и чека активирање од администраторот.']);
        $this->redirect('/login','Регистрацијата е примена. Администраторот ќе ја активира сметката и ќе ви додели пристап.');
    }

    public function user(): never
    {
        [$name,$username,$email,$passwordHash]=$this->userIdentity();
        $this->assertUniqueIdentity($username,$email);
        $status=$this->requireField('status',20);if(!in_array($status,['active','disabled'],true))throw new HttpException(422,'Статусот не е валиден.');
        [$role,$directorate]=$this->roleScope(true);
        $id=$this->db->transaction(function()use($name,$username,$email,$passwordHash,$status,$role,$directorate){$id=$this->db->insertId('INSERT INTO users(name,username,email,email_verified_at,password,locale,status,last_login_at,remember_token,created_at,updated_at) VALUES(:name,:username,:email,SYSUTCDATETIME(),:password,:locale,:status,NULL,NULL,SYSUTCDATETIME(),SYSUTCDATETIME())',['name'=>$name,'username'=>$username,'email'=>$email,'password'=>$passwordHash,'locale'=>'mk','status'=>$status]);$this->db->execute('INSERT INTO user_role_scopes(user_id,role_id,directorate_id,program_id,project_id,valid_from,valid_to,granted_by,reason,created_at,updated_at) VALUES(:user,:role,:directorate,NULL,NULL,SYSUTCDATETIME(),NULL,:granted,:reason,SYSUTCDATETIME(),SYSUTCDATETIME())',['user'=>$id,'role'=>$role['id'],'directorate'=>$directorate,'granted'=>$this->userId(),'reason'=>'Создадено од администраторот']);$this->audit->record($this->userId(),'user.created','User',$id,[],['username'=>$username,'email'=>$email,'status'=>$status,'role'=>$role['code'],'directorate_id'=>$directorate]);return$id;});
        $this->notifications->notify($id,'user_created','Вашата сметка во е-Кабинет е создадена · #'.$id,['user_id'=>$id,'body'=>'Администраторот ја создаде вашата сметка со корисничко име '.$username.'. Статус: '.$status.'.']);
        $this->redirect('/users','Корисникот е создаден и пристапот е доделен.');
    }

    public function userAccess(): never
    {
        $id=$this->intField('user_id',1,PHP_INT_MAX);$user=$this->db->one('SELECT id FROM users WHERE id=:id',['id'=>$id]);if(!$user)throw new HttpException(404,'Корисникот не е пронајден.');
        $status=$this->requireField('status',20);if(!in_array($status,['active','disabled','locked'],true))throw new HttpException(422,'Статусот не е валиден.');
        $changes=$this->applyAccessChanges($id,$status);
        $this->notifications->notify($id,'user_access_updated','Пристапот до е-Кабинет е ажуриран · #'.$id,['user_id'=>$id,'body'=>'Статус: '.$status.'. Активни улоги: '.($changes['roles']?:'без улога').'.']);
        $this->redirect('/users','Статусот и пристапот на корисникот се ажурирани.');
    }

    public function userUpdate(int $id): never
    {
        $name=$this->requireField('name',255);$username=mb_strtolower($this->requireField('username',100));$email=mb_strtolower($this->requireField('email',255));$status=$this->requireField('status',20);$token=$this->requireField('updated_at',23);
        if(!preg_match('/^[a-z0-9._-]{3,100}$/',$username)||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpException(422,'Корисничкото име или е-поштата не се валидни.');if(!in_array($status,['active','disabled','locked'],true))throw new HttpException(422,'Статусот не е валиден.');
        $this->db->transaction(function()use($id,$name,$username,$email,$token){$before=$this->db->one('SELECT id,name,username,email,status,CONVERT(varchar(23),updated_at,121) updated_at_token FROM users WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Корисникот не е пронајден.');if(!hash_equals((string)$before['updated_at_token'],$token))throw new HttpException(409,'Корисникот е изменет. Освежете ја страницата.');if($this->db->scalar('SELECT TOP 1 1 FROM users WHERE id<>:id AND (LOWER(username)=:username OR LOWER(email)=:email)',['id'=>$id,'username'=>$username,'email'=>$email]))throw new HttpException(422,'Корисничкото име или е-поштата веќе постојат.');$this->db->execute('UPDATE users SET name=:name,username=:username,email=:email,updated_at=SYSUTCDATETIME() WHERE id=:id',['name'=>$name,'username'=>$username,'email'=>$email,'id'=>$id]);unset($before['updated_at_token']);$this->audit->record($this->userId(),'user.updated','User',$id,$before,['name'=>$name,'username'=>$username,'email'=>$email]);});
        $this->applyAccessChanges($id,$status);
        $this->redirect('/users/'.$id.'/edit','Корисникот е успешно уреден.');
    }

    /**
     * Applies status, expires the selected role scopes (expire_scope_ids[]) and adds at most one new scope (role_id + directorate_id).
     * Other active scopes are kept, so users with several roles do not lose access.
     */
    private function applyAccessChanges(int $id,string $status): array
    {
        $expire=$this->postedIds('expire_scope_ids');[$role,$directorate]=$this->roleScope(false);
        return $this->db->transaction(function()use($id,$status,$expire,$role,$directorate){
            $user=$this->db->one('SELECT id,status FROM users WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$user)throw new HttpException(404,'Корисникот не е пронајден.');
            $active=$this->db->all("SELECT urs.id,urs.directorate_id,r.id role_id,r.code FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=:id AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['id'=>$id]);
            $activeIds=array_map('intval',array_column($active,'id'));
            foreach($expire as$scopeId)if(!in_array($scopeId,$activeIds,true))throw new HttpException(422,'Пристапот избран за отстранување не е активен.');
            if($id===$this->userId()){
                $remainingAdmin=array_filter($active,static fn(array$s):bool=>$s['code']==='system_admin'&&!in_array((int)$s['id'],$expire,true));
                if($status!=='active'||$remainingAdmin===[])throw new HttpException(422,'Не можете да се деактивирате себеси или да си ја отстраните улогата system_admin.');
            }
            $before=['status'=>$user['status'],'scopes'=>$active];
            if($expire){$params=['id'=>$id];$marks=[];foreach($expire as$i=>$scopeId){$marks[]=':s'.$i;$params['s'.$i]=$scopeId;}$this->db->execute('UPDATE user_role_scopes SET valid_to=DATEADD(second,-1,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE user_id=:id AND id IN ('.implode(',',$marks).')',$params);}
            $added=null;
            if($role!==null){
                $duplicate=array_filter($active,static fn(array$s):bool=>(int)$s['role_id']===(int)$role['id']&&(int)($s['directorate_id']??0)===(int)($directorate??0)&&!in_array((int)$s['id'],$expire,true));
                if($duplicate===[]){$added=$this->db->insertId('INSERT INTO user_role_scopes(user_id,role_id,directorate_id,program_id,project_id,valid_from,valid_to,granted_by,reason,created_at,updated_at) VALUES(:user,:role,:directorate,NULL,NULL,SYSUTCDATETIME(),NULL,:granted,:reason,SYSUTCDATETIME(),SYSUTCDATETIME())',['user'=>$id,'role'=>$role['id'],'directorate'=>$directorate,'granted'=>$this->userId(),'reason'=>'Пристапот е додаден од администраторот']);}
            }
            $this->db->execute('UPDATE users SET status=:status,email_verified_at=CASE WHEN :status2=:active THEN COALESCE(email_verified_at,SYSUTCDATETIME()) ELSE email_verified_at END,updated_at=SYSUTCDATETIME() WHERE id=:id',['status'=>$status,'status2'=>$status,'active'=>'active','id'=>$id]);
            $changed=$expire!==[]||$added!==null||$user['status']!==$status;
            if($changed){$this->auth->revokeSessions($id);$this->audit->record($this->userId(),'user.access_updated','User',$id,$before,['status'=>$status,'expired_scope_ids'=>$expire,'added_scope_id'=>$added,'role'=>$role['code']??null,'directorate_id'=>$directorate]);}
            $roles=$this->db->all("SELECT r.name FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=:id AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['id'=>$id]);
            return['changed'=>$changed,'roles'=>implode(', ',array_column($roles,'name'))];
        });
    }

    public function userPasswordReset(int $id): never
    {
        if($id===$this->userId())throw new HttpException(422,'За вашата сметка користете ја страницата Безбедност на профилот.');$password=$this->requireField('new_password',4096);$confirmation=$this->requireField('new_password_confirmation',4096);unset($_POST['new_password'],$_POST['new_password_confirmation']);if($password!==$confirmation)throw new HttpException(422,'Потврдата на лозинката не се совпаѓа.');self::assertPasswordPolicy($password);$user=$this->db->one('SELECT id,name,username,password,status FROM users WHERE id=:id',['id'=>$id]);if(!$user)throw new HttpException(404,'Корисникот не е пронајден.');if(password_verify($password,(string)$user['password']))throw new HttpException(422,'Новата лозинка мора да се разликува од тековната.');$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);if($hash===false)throw new \RuntimeException('Генерирањето на хашот на лозинката не успеа.');$this->db->transaction(function()use($id,$hash,$user){$this->db->execute('UPDATE users SET password=:password,remember_token=NULL,updated_at=SYSUTCDATETIME() WHERE id=:id',['password'=>$hash,'id'=>$id]);$this->auth->revokeSessions($id);$this->audit->record($this->userId(),'user.password_reset_by_admin','User',$id,['username'=>$user['username'],'status'=>$user['status']],['sessions_revoked'=>true]);});$password=$confirmation=$hash='';$this->notifications->notify($id,'password_reset_by_admin','Лозинката е ресетирана од администраторот · '.gmdate('Y-m-d H:i:s').' UTC',['user_id'=>$id,'body'=>'Администраторот ја промени вашата лозинка и претходните сесии се затворени. Најавете се со новата лозинка.']);$this->redirect('/users/'.$id.'/edit','Лозинката е променета и активните сесии се затворени.');
    }

    public function userDelete(int $id): never
    {
        if($id===$this->userId())throw new HttpException(422,'Не можете да ја избришете својата сметка.');$user=$this->db->one('SELECT id,name,username,email FROM users WHERE id=:id',['id'=>$id]);if(!$user)throw new HttpException(404,'Корисникот не е пронајден.');
        // Audit history is append-only: users that ever acted in the system can only be deactivated.
        if($this->db->scalar('SELECT TOP 1 1 FROM audit_events WHERE actor_id=:id',['id'=>$id]))throw new HttpException(409,'Корисникот има историја во ревизијата и не може да се избрише. Деактивирајте го наместо да го бришете.');
        try{$this->db->transaction(function()use($id){$this->db->execute('DELETE FROM notifications WHERE recipient_id=:id',['id'=>$id]);$this->db->execute('DELETE FROM user_role_scopes WHERE user_id=:id',['id'=>$id]);$this->db->execute('DELETE FROM users WHERE id=:id',['id'=>$id]);});}catch(\PDOException){throw new HttpException(409,'Корисникот има проекти, предмети, извештаи или други врски и не може да се избрише. Деактивирајте го наместо да го бришете.');}
        $this->audit->record($this->userId(),'user.deleted','User',$id,$user,[],'Без поврзани проекти, предмети или извештаи');$this->redirect('/users','Корисникот е избришан.');
    }

    public function directorate(): never
    {
        if(!$this->scope->canManageDirectorates($this->userId()))throw new HttpException(403,'Само Администраторот на системот и Градоначалникот можат да ги администрираат секторите.');
        $code=mb_strtoupper($this->requireField('code',30));
        $name=$this->requireField('name',200);
        if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/',$code))throw new HttpException(422,'Шифрата мора да има 2-30 знаци и може да содржи само букви, бројки, _ или -.');
        if(mb_strlen($name)<3)throw new HttpException(422,'Називот на секторот мора да има најмалку 3 знаци.');
        $budgetCodes=$this->budgetCodes();
        $id=$this->db->transaction(function()use($code,$name,$budgetCodes){
            if($this->db->scalar('SELECT TOP 1 1 FROM directorates WITH (UPDLOCK,HOLDLOCK) WHERE UPPER(code)=:code',['code'=>$code]))throw new HttpException(422,'Веќе постои сектор со оваа шифра.');
            $id=$this->db->insertId('INSERT INTO directorates(code,name,active,created_at,updated_at) VALUES(:code,:name,1,SYSUTCDATETIME(),SYSUTCDATETIME())',['code'=>$code,'name'=>$name]);
            try{(new BudgetExecution($this->db))->assignDirectorateCodes($id,$budgetCodes,$this->userId(),$name);}catch(\InvalidArgumentException $e){throw new HttpException(422,$e->getMessage());}
            $this->audit->record($this->userId(),'directorate.created','App\\Models\\Directorate',$id,[],['code'=>$code,'name'=>$name,'active'=>true,'budget_codes'=>$budgetCodes]);
            return$id;
        });
        $this->redirect('/directorates','Сектор '.$code.' е успешно создаден. ID: '.$id.'.');
    }

    public function directorateUpdate(int $id): never
    {
        if(!$this->scope->canManageDirectorates($this->userId()))throw new HttpException(403,'Само Администраторот на системот и Градоначалникот можат да ги администрираат секторите.');
        $code=mb_strtoupper($this->requireField('code',30));$name=$this->requireField('name',200);$active=$this->intField('active',0,1);$token=$this->requireField('updated_at',23);
        if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/',$code))throw new HttpException(422,'Шифрата мора да има 2-30 знаци и може да содржи само букви, бројки, _ или -.');
        if(mb_strlen($name)<3)throw new HttpException(422,'Називот на секторот мора да има најмалку 3 знаци.');
        $budgetCodes=$this->budgetCodes();
        $this->db->transaction(function()use($id,$code,$name,$active,$token,$budgetCodes){
            $before=$this->db->one('SELECT id,code,name,active,budget_codes,CONVERT(varchar(23),updated_at,121) updated_at_token FROM directorates WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Секторот не е пронајден.');
            if(!hash_equals((string)$before['updated_at_token'],$token))throw new HttpException(409,'Секторот е изменет од друг корисник. Освежете ја страницата.');
            if($this->db->scalar('SELECT TOP 1 1 FROM directorates WITH (UPDLOCK,HOLDLOCK) WHERE UPPER(code)=:code AND id<>:id',['code'=>$code,'id'=>$id]))throw new HttpException(422,'Веќе постои сектор со оваа шифра.');
            $this->db->execute('UPDATE directorates SET code=:code,name=:name,active=:active,updated_at=SYSUTCDATETIME() WHERE id=:id',['code'=>$code,'name'=>$name,'active'=>$active,'id'=>$id]);
            try{$codeChanges=(new BudgetExecution($this->db))->assignDirectorateCodes($id,$budgetCodes,$this->userId(),$name);}catch(\InvalidArgumentException $e){throw new HttpException(422,$e->getMessage());}
            $this->audit->record($this->userId(),'directorate.updated','App\\Models\\Directorate',$id,['code'=>$before['code'],'name'=>$before['name'],'active'=>(bool)$before['active'],'budget_codes'=>$before['budget_codes']],['code'=>$code,'name'=>$name,'active'=>(bool)$active,'budget_codes'=>implode(', ',$budgetCodes)]+$codeChanges);
        });
        $this->redirect('/directorates','Секторот е успешно ажуриран.');
    }

    /** POST /directorates/{id}/status (active 0/1): quick activation / deactivation from the list. */
    public function directorateStatus(int $id): never
    {
        if(!$this->scope->canManageDirectorates($this->userId()))throw new HttpException(403,'Само Администраторот на системот и Градоначалникот можат да ги администрираат секторите.');
        $active=$this->intField('active',0,1);
        $before=$this->db->one('SELECT id,code,name,active FROM directorates WHERE id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Секторот не е пронајден.');
        $this->db->execute('UPDATE directorates SET active=:active,updated_at=SYSUTCDATETIME() WHERE id=:id',['active'=>$active,'id'=>$id]);
        $this->audit->record($this->userId(),$active?'directorate.activated':'directorate.deactivated','App\\Models\\Directorate',$id,['active'=>(bool)$before['active']],['active'=>(bool)$active]);
        $this->redirect('/directorates',$active?'Сектор '.$before['code'].' е активиран.':'Сектор '.$before['code'].' е деактивиран: повеќе не се појавува во новите обрасци, историјата се чува.');
    }

    /** POST /directorates/{id}/delete (reason): only a directorate without users, projects, budget, cases or reports. */
    public function directorateDelete(int $id): never
    {
        if(!$this->scope->canManageDirectorates($this->userId()))throw new HttpException(403,'Само Администраторот на системот и Градоначалникот можат да ги администрираат секторите.');
        $reason=$this->requireField('reason',500);
        $directorate=$this->db->one('SELECT id,code,name,active,budget_codes FROM directorates WHERE id=:id',['id'=>$id]);if(!$directorate)throw new HttpException(404,'Секторот не е пронајден.');
        $blocking=['user_role_scopes'=>'пристапи на корисници','projects'=>'projekte','risks'=>'rreziqe','budget_lines'=>'буџетски ставки','mayor_agenda_items'=>'точки на агендата','case_directorates'=>'предмети'];
        foreach($blocking as$table=>$label){
            try{$used=(int)$this->db->scalar("SELECT COUNT(*) FROM {$table} WHERE directorate_id=:id",['id'=>$id]);}catch(\PDOException){$used=0;}
            if($used>0)throw new HttpException(409,'Секторот има '.$label.' и не може да се избрише. Деактивирајте го: историјата се чува.');
        }
        try{
            $this->db->transaction(function()use($id){
                // Catalog items the directorate owns without any report: its empty sectors and computed windows.
                $this->db->execute('DELETE FROM citizen_service_sector_reporters WHERE sector_id IN (SELECT id FROM citizen_service_sectors WHERE directorate_id=:id)',['id'=>$id]);
                $this->db->execute('DELETE FROM citizen_service_catalog WHERE sector_id IN (SELECT id FROM citizen_service_sectors WHERE directorate_id=:id)',['id'=>$id]);
                $this->db->execute('DELETE FROM citizen_service_sectors WHERE directorate_id=:id',['id'=>$id]);
                $this->db->execute('DELETE FROM citizen_service_metrics WHERE service_id IN (SELECT id FROM citizen_services WHERE directorate_id=:id)',['id'=>$id]);
                $this->db->execute('DELETE FROM citizen_services WHERE directorate_id=:id',['id'=>$id]);
                $this->db->execute('DELETE FROM directorates WHERE id=:id',['id'=>$id]);
            });
        }catch(\PDOException){throw new HttpException(409,'Секторот има поврзани податоци (извештаи, буџет, предмети или историја) и не може да се избрише. Деактивирајте го: историјата се чува.');}
        $this->audit->record($this->userId(),'directorate.deleted','App\\Models\\Directorate',$id,$directorate,[],$reason);
        $this->redirect('/directorates','Сектор '.$directorate['code'].' е избришан.');
    }

    public function mayorAgendaSelection(int $directorateId): never
    {
        if(!$this->scope->canManageMayorAgenda($this->userId(),$directorateId))throw new HttpException(403,'Немате дозвола за агендата на овој сектор.');
        $projectIds=$this->postedIds('project_ids');$riskIds=$this->postedIds('risk_ids');
        $this->assertAgendaEntities('projects',$projectIds,$directorateId);$this->assertAgendaEntities('risks',$riskIds,$directorateId);
        $added=$this->db->transaction(function()use($directorateId,$projectIds,$riskIds){
            $directorate=$this->db->one('SELECT id,code,name FROM directorates WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$directorateId]);if(!$directorate)throw new HttpException(404,'Секторот не е пронајден.');
            $before=$this->db->all("SELECT id,item_type,project_id,risk_id FROM mayor_agenda_items WITH (UPDLOCK,HOLDLOCK) WHERE directorate_id=:id AND status='open' AND item_type IN ('project','risk')",['id'=>$directorateId]);
            $currentProjects=[];$currentRisks=[];foreach($before as$item){if($item['item_type']==='project')$currentProjects[(int)$item['project_id']]=(int)$item['id'];else$currentRisks[(int)$item['risk_id']]=(int)$item['id'];}
            foreach($currentProjects as$entityId=>$itemId)if(!in_array($entityId,$projectIds,true))$this->closeMayorAgendaItem($itemId,'removed');
            foreach($currentRisks as$entityId=>$itemId)if(!in_array($entityId,$riskIds,true))$this->closeMayorAgendaItem($itemId,'removed');
            $added=0;foreach($projectIds as$entityId)if(!isset($currentProjects[$entityId])){$this->db->execute("INSERT INTO mayor_agenda_items(directorate_id,item_type,project_id,risk_id,topic_title,topic_details,priority,discussion_date,status,selected_by,selected_at,created_at,updated_at) VALUES(:directorate,'project',:entity,NULL,NULL,NULL,'normal',NULL,'open',:user,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())",['directorate'=>$directorateId,'entity'=>$entityId,'user'=>$this->userId()]);$added++;}
            foreach($riskIds as$entityId)if(!isset($currentRisks[$entityId])){$this->db->execute("INSERT INTO mayor_agenda_items(directorate_id,item_type,project_id,risk_id,topic_title,topic_details,priority,discussion_date,status,selected_by,selected_at,created_at,updated_at) VALUES(:directorate,'risk',NULL,:entity,NULL,NULL,'normal',NULL,'open',:user,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())",['directorate'=>$directorateId,'entity'=>$entityId,'user'=>$this->userId()]);$added++;}
            $this->audit->record($this->userId(),'mayor_agenda.selection_updated','App\\Models\\Directorate',$directorateId,['projects'=>array_keys($currentProjects),'risks'=>array_keys($currentRisks)],['projects'=>$projectIds,'risks'=>$riskIds]);return$added;
        });
        if($added>0)$this->notifyMayors('Агендата на Градоначалникот е ажурирана',['directorate_id'=>$directorateId,'body'=>'Додадени се '.$added.' нови точки за дискусија или внимание на Градоначалникот.']);
        $this->redirect('/directorates/'.$directorateId,'Изборот за агендата е зачуван.');
    }

    public function mayorAgendaTopic(int $directorateId): never
    {
        if(!$this->scope->canManageMayorAgenda($this->userId(),$directorateId))throw new HttpException(403,'Немате дозвола за агендата на овој сектор.');
        $title=$this->requireField('topic_title',250);$details=trim((string)$this->request->input('topic_details',''))?:null;if($details!==null&&mb_strlen($details)>10000)throw new HttpException(422,'Појаснувањето на темата е предолго.');$priority=$this->requireField('priority',20);if(!in_array($priority,['normal','high','critical'],true))throw new HttpException(422,'Приоритетот не е валиден.');$discussionDate=$this->date('discussion_date',false);
        $id=$this->db->transaction(function()use($directorateId,$title,$details,$priority,$discussionDate){if(!$this->db->scalar('SELECT TOP 1 1 FROM directorates WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$directorateId]))throw new HttpException(404,'Секторот не е пронајден.');$id=$this->db->insertId("INSERT INTO mayor_agenda_items(directorate_id,item_type,project_id,risk_id,topic_title,topic_details,priority,discussion_date,status,selected_by,selected_at,created_at,updated_at) VALUES(:directorate,'topic',NULL,NULL,:title,:details,:priority,:discussion_date,'open',:user,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())",['directorate'=>$directorateId,'title'=>$title,'details'=>$details,'priority'=>$priority,'discussion_date'=>$discussionDate,'user'=>$this->userId()]);$this->audit->record($this->userId(),'mayor_agenda.topic_created','App\\Models\\MayorAgendaItem',$id,[],['directorate_id'=>$directorateId,'title'=>$title,'details'=>$details,'priority'=>$priority,'discussion_date'=>$discussionDate]);return$id;});
        $this->notifyMayors('Нова тема за дискусија · #'.$id,['directorate_id'=>$directorateId,'mayor_agenda_item_id'=>$id,'body'=>$title]);$this->redirect('/directorates/'.$directorateId,'Темата е додадена на панелот на Градоначалникот.');
    }

    public function mayorAgendaUpdate(int $directorateId,int $itemId): never
    {
        if(!$this->scope->canManageMayorAgenda($this->userId(),$directorateId))throw new HttpException(403,'Немате дозвола за агендата на овој сектор.');$priority=$this->requireField('priority',20);if(!in_array($priority,['normal','high','critical'],true))throw new HttpException(422,'Приоритетот не е валиден.');$discussionDate=$this->date('discussion_date',false);$details=trim((string)$this->request->input('topic_details',''))?:null;if($details!==null&&mb_strlen($details)>10000)throw new HttpException(422,'Појаснувањето е предолго.');$token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9A-F]{16}$/i',$token))throw new HttpException(422,'Верзијата на агендата не е валидна. Освежете ја страницата.');
        $this->db->transaction(function()use($directorateId,$itemId,$priority,$discussionDate,$details,$token){$before=$this->db->one("SELECT *,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM mayor_agenda_items WITH (UPDLOCK,HOLDLOCK) WHERE id=:id AND directorate_id=:directorate AND status='open'",['id'=>$itemId,'directorate'=>$directorateId]);if(!$before)throw new HttpException(404,'Активната точка од агендата не е пронајдена.');if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Агендата е изменета од друг корисник. Освежете ја страницата.');$title=$before['item_type']==='topic'?$this->requireField('topic_title',250):null;$this->db->execute('UPDATE mayor_agenda_items SET topic_title=:title,topic_details=:details,priority=:priority,discussion_date=:discussion_date,updated_at=SYSUTCDATETIME() WHERE id=:id',['title'=>$title,'details'=>$details,'priority'=>$priority,'discussion_date'=>$discussionDate,'id'=>$itemId]);$auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'mayor_agenda.item_updated','App\\Models\\MayorAgendaItem',$itemId,$auditBefore,['topic_title'=>$title,'topic_details'=>$details,'priority'=>$priority,'discussion_date'=>$discussionDate]);});$this->redirect('/directorates/'.$directorateId,'Точката од агендата е ажурирана.');
    }

    public function mayorAgendaStatus(int $directorateId,int $itemId): never
    {
        if(!$this->scope->canManageMayorAgenda($this->userId(),$directorateId))throw new HttpException(403,'Немате дозвола за агендата на овој сектор.');$status=$this->requireField('status',20);if(!in_array($status,['discussed','removed'],true))throw new HttpException(422,'Статусот на агендата не е валиден.');
        $this->db->transaction(function()use($directorateId,$itemId,$status){$before=$this->db->one("SELECT * FROM mayor_agenda_items WITH (UPDLOCK,HOLDLOCK) WHERE id=:id AND directorate_id=:directorate AND status='open'",['id'=>$itemId,'directorate'=>$directorateId]);if(!$before)throw new HttpException(404,'Активната точка од агендата не е пронајдена.');$this->closeMayorAgendaItem($itemId,$status);$auditBefore=$before;unset($auditBefore['row_version']);$this->audit->record($this->userId(),'mayor_agenda.item_'.$status,'App\\Models\\MayorAgendaItem',$itemId,$auditBefore,['status'=>$status,'resolved_by'=>$this->userId()]);});
        $this->redirect('/directorates/'.$directorateId,$status==='discussed'?'Точката е означена како разгледана.':'Точката е отстранета од активната агенда.');
    }

    public function caseMayorAgenda(int $caseId): never
    {
        if(array_intersect(['mayor','director'],$this->scope->roles($this->userId()))===[])throw new HttpException(403,'Само Градоначалникот и раководителот на секторот можат да го изберат предметот за агендата.');
        $case=(new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId);
        if(!$this->scope->canManageMayorAgenda($this->userId(),(int)$case['originating_directorate_id']))throw new HttpException(403,'Раководителот на секторот може да избира само предмети од својот сектор.');
        $action=$this->requireField('action',10);if(!in_array($action,['add','remove'],true))throw new HttpException(422,'Дејството за агендата не е валидно.');
        $this->db->transaction(function()use($caseId,$case,$action){
            $current=$this->db->one("SELECT TOP 1 * FROM mayor_agenda_items WITH (UPDLOCK,HOLDLOCK) WHERE item_type='case' AND case_id=:case AND status='open'",['case'=>$caseId]);
            if($action==='add'&&!$current){$id=$this->db->insertId("INSERT INTO mayor_agenda_items(directorate_id,item_type,project_id,risk_id,case_id,topic_title,topic_details,priority,discussion_date,status,selected_by,selected_at,created_at,updated_at) VALUES(:directorate,'case',NULL,NULL,:case,NULL,NULL,:priority,NULL,'open',:user,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())",['directorate'=>(int)$case['originating_directorate_id'],'case'=>$caseId,'priority'=>in_array($case['priority'],['normal','high','critical'],true)?$case['priority']:'normal','user'=>$this->userId()]);$this->audit->record($this->userId(),'mayor_agenda.case_added','AdministrativeCase',$caseId,[],['agenda_item_id'=>$id]);}
            if($action==='remove'&&$current){$this->closeMayorAgendaItem((int)$current['id'],'removed');$this->audit->record($this->userId(),'mayor_agenda.case_removed','AdministrativeCase',$caseId,['agenda_item_id'=>(int)$current['id']],['status'=>'removed']);}
        });
        $this->redirect('/cases/'.$caseId,$action==='add'?'Предметот е избран за агендата на Градоначалникот.':'Предметот е отстранет од тековната агенда.');
    }

    public function project(): never
    {
        $directorate=$this->intField('directorate_id',1,PHP_INT_MAX);if(!$this->scope->allowsDirectorate($this->userId(),$directorate))throw new HttpException(403,'Надвор од вашиот опсег.');
        $manager=$this->intField('manager_id',1,PHP_INT_MAX);
        if(!$this->db->scalar("SELECT TOP 1 1 FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.id=:manager AND u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','deputy_mayor','director','directors_assistant','project_manager','system_admin') AND (urs.directorate_id=:d OR COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','system_admin')) AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['manager'=>$manager,'d'=>$directorate]))throw new HttpException(422,'Избраниот носител / одговорно лице нема активна улога за овој сектор.');
        $starts=$this->date('starts_at',false);$ends=$this->date('ends_at',false);if($starts&&$ends&&$ends<$starts)throw new HttpException(422,'Крајниот датум мора да биде по почетокот.');
        $latitude=trim((string)$this->request->input('latitude',''));$longitude=trim((string)$this->request->input('longitude',''));if($latitude!==''&&!preg_match('/^-?\d{1,3}(?:\.\d{1,7})?$/',$latitude))throw new HttpException(422,'Географската ширина не е валидна.');if($longitude!==''&&!preg_match('/^-?\d{1,3}(?:\.\d{1,7})?$/',$longitude))throw new HttpException(422,'Географската должина не е валидна.');$params=['d'=>$directorate,'code'=>$this->requireField('code',50),'name'=>$this->requireField('name',250),'objective'=>trim((string)$this->request->input('objective',''))?:null,'starts'=>$starts,'ends'=>$ends,'budget'=>$this->request->input('has_budget_impact')?1:0,'manager'=>$manager,'location'=>trim((string)$this->request->input('location_name',''))?:null,'address'=>trim((string)$this->request->input('address',''))?:null,'latitude'=>$latitude!==''?$latitude:null,'longitude'=>$longitude!==''?$longitude:null,'maps'=>trim((string)$this->request->input('google_maps_url',''))?:null,'category'=>$this->projectBudgetCategory()];
        $geo=GeoReference::parse($_POST);$params['latitude']=$geo['latitude'];$params['longitude']=$geo['longitude'];$params['maps']=$geo['google_maps_url'];
        foreach(['location'=>250,'address'=>500] as$field=>$max)if($params[$field]!==null&&mb_strlen((string)$params[$field])>$max)throw new HttpException(422,'Локацијата или адресата е предолга.');
        if($this->db->scalar('SELECT TOP 1 1 FROM projects WHERE directorate_id=:d AND code=:code',['d'=>$directorate,'code'=>$params['code']]))throw new HttpException(422,'Постои друг проект со оваа шифра во секторот.');
        $id=$this->db->insertId("INSERT INTO projects (directorate_id,code,name,objective,starts_at,ends_at,status,has_budget_impact,budget_category,progress,manager_id,location_name,address,latitude,longitude,google_maps_url,created_at,updated_at) VALUES (:d,:code,:name,:objective,:starts,:ends,'draft',:budget,:category,0,:manager,:location,:address,:latitude,:longitude,:maps,SYSUTCDATETIME(),SYSUTCDATETIME())",$params);
        $this->audit->record($this->userId(),'project.created','App\\Models\\Project',$id,[],$params);$uploads=$this->storeInitialEvidence($id);$this->redirect('/projects/'.$id,'Проектот е создаден.'.($uploads?' '.$uploads.' датотеки се ставени во карантин.':''));
    }

    public function projectUpdate(int $id): never
    {
        $code=$this->requireField('code',50);$name=$this->requireField('name',250);$objective=trim((string)$this->request->input('objective',''))?:null;if($objective!==null&&mb_strlen($objective)>10000)throw new HttpException(422,'Целта е предолга.');
        $geo=GeoReference::parse($_POST);$location=trim((string)$this->request->input('location_name',''))?:null;$address=trim((string)$this->request->input('address',''))?:null;if(($location!==null&&mb_strlen($location)>250)||($address!==null&&mb_strlen($address)>500))throw new HttpException(422,'Локацијата или адресата е предолга.');
            $starts=$this->date('starts_at',false);$ends=$this->date('ends_at',false);if($starts&&$ends&&$ends<$starts)throw new HttpException(422,'Крајниот датум мора да биде по почетокот.');
            $status=$this->requireField('status',30);$manager=$this->intField('manager_id',1,PHP_INT_MAX);$budget=$this->request->input('has_budget_impact')?1:0;$category=$this->projectBudgetCategory();$rowVersion=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9A-F]{16}$/i',$rowVersion))throw new HttpException(422,'Верзијата на проектот не е валидна. Освежете ја страницата.');
        $changedManager=false;
        $this->db->transaction(function()use($id,$code,$name,$objective,$starts,$ends,$status,$manager,$budget,$category,$rowVersion,$geo,$location,$address,&$changedManager){
            $before=$this->db->one('SELECT id,directorate_id,code,name,manager_id,objective,location_name,address,latitude,longitude,CONVERT(varchar(10),starts_at,23) starts_at,CONVERT(varchar(10),ends_at,23) ends_at,status,has_budget_impact,budget_category,progress,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM projects WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Проектот не е пронајден.');
            (new EvidenceAccess($this->db,$this->scope,$this->userId()))->project($id,true);
            if(!hash_equals(strtoupper((string)$before['row_version_token']),strtoupper($rowVersion)))throw new HttpException(409,'Проектот е изменет од друг корисник. Освежете ја страницата.');
            if(!in_array($status,['draft','active','on_hold','completed','cancelled'],true)&&$status!==$before['status'])throw new HttpException(422,'Статусот на проектот не е валиден.');
            if($status==='completed'&&(int)$before['progress']<100)throw new HttpException(422,'Проектот може да се означи како завршен само откако известувањето ќе достигне 100%.');
            if($budget===0&&(bool)$before['has_budget_impact']&&$this->db->scalar('SELECT TOP 1 1 FROM budget_allocations WHERE project_id=:id',['id'=>$id]))throw new HttpException(422,'Буџетското влијание не може да се отстрани бидејќи проектот има буџетски распределби.');
            if($category!==(string)$before['budget_category']&&$this->db->scalar('SELECT TOP 1 1 FROM budget_allocations ba JOIN budget_lines bl ON bl.id=ba.budget_line_id WHERE ba.project_id=:id AND bl.budget_category<>:category',['id'=>$id,'category'=>$category]))throw new HttpException(422,'Буџетската категорија не може да се промени бидејќи проектот има распределби во ставки од друга категорија.');
            if($this->db->scalar('SELECT TOP 1 1 FROM projects WITH (UPDLOCK,HOLDLOCK) WHERE directorate_id=:d AND code=:code AND id<>:id',['d'=>(int)$before['directorate_id'],'code'=>$code,'id'=>$id]))throw new HttpException(422,'Постои друг проект со оваа шифра во секторот.');
            if((int)$before['manager_id']!==$manager&&!$this->db->scalar("SELECT TOP 1 1 FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.id=:manager AND u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','deputy_mayor','director','directors_assistant','project_manager','system_admin') AND (urs.directorate_id=:d OR COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','system_admin')) AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['manager'=>$manager,'d'=>(int)$before['directorate_id']]))throw new HttpException(422,'Избраниот носител / одговорно лице нема активна улога за овој сектор.');
            $this->db->execute('UPDATE projects SET code=:code,name=:name,manager_id=:manager,objective=:objective,starts_at=:starts,ends_at=:ends,status=:status,has_budget_impact=:budget,budget_category=:category,location_name=:location,address=:address,latitude=:latitude,longitude=:longitude,google_maps_url=:google_maps_url,updated_at=SYSUTCDATETIME() WHERE id=:id',['code'=>$code,'name'=>$name,'manager'=>$manager,'objective'=>$objective,'starts'=>$starts,'ends'=>$ends,'status'=>$status,'budget'=>$budget,'category'=>$category,'location'=>$location,'address'=>$address,'id'=>$id]+$geo);
            $after=['code'=>$code,'name'=>$name,'manager_id'=>$manager,'objective'=>$objective,'starts_at'=>$starts,'ends_at'=>$ends,'status'=>$status,'has_budget_impact'=>(bool)$budget,'budget_category'=>$category,'location_name'=>$location,'address'=>$address]+$geo;$auditBefore=$before;unset($auditBefore['row_version_token']);$this->audit->record($this->userId(),'project.updated','App\\Models\\Project',$id,$auditBefore,$after);$changedManager=(int)($before['manager_id']??0)!==(int)($manager??0);
        });
        if($changedManager)$this->notifications->notify($manager,'project_manager_assigned','Определени сте за одговорно лице на проект · #'.$id,['project_id'=>$id,'body'=>'Определени сте за носител / одговорно лице на проектот '.$code.' · '.$name.'.']);
        $this->redirect('/projects/'.$id,'Проектот е успешно ажуриран.');
    }

    public function projectBudget(int $projectId): never
    {
        $amount=$this->amount('amount');$lineId=$this->intField('budget_line_id',1,PHP_INT_MAX);$retroactive=(bool)$this->request->input('retroactive');
            $result=$this->db->transaction(function()use($projectId,$lineId,$amount,$retroactive){
                $project=$this->db->one('SELECT * FROM projects WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$projectId]);if(!$project)throw new HttpException(404,'Проектот не е пронајден.');(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true);
            $line=$this->db->one('SELECT bl.*,bc.base_year fiscal_year,bv.is_official FROM budget_lines bl WITH (UPDLOCK,HOLDLOCK) JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bl.id=:id',['id'=>$lineId]);if(!$line)throw new HttpException(404,'Буџетската ставка не е пронајдена.');if(!$this->scope->allowsFinancial($this->userId(),(int)$project['directorate_id']))throw new HttpException(403,'Немате финансиска улога за секторот на проектот.');if(!(bool)$line['is_official']||$line['budget_category']===null)throw new HttpException(422,'Изберете ставка од одобрениот буџет.');$this->assertProjectCategory($project,$line);
            if($this->db->scalar('SELECT TOP 1 1 FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE budget_line_id=:line AND project_id=:project AND task_id IS NULL AND fiscal_year=:year AND period=0',['line'=>$lineId,'project'=>$projectId,'year'=>(int)$line['fiscal_year']]))throw new HttpException(422,'Проектот е веќе поврзан со оваа буџетска ставка.');
            $remaining=(string)$this->db->scalar('SELECT CAST(bl.total_amount-COALESCE((SELECT SUM(ba.current_amount) FROM budget_allocations ba WITH (UPDLOCK,HOLDLOCK) WHERE ba.budget_line_id=bl.id),0) AS decimal(19,4)) FROM budget_lines bl WHERE bl.id=:line',['line'=>$lineId]);if(Money::compare($line['total_amount'],'0')<=0||Money::compare($amount,$remaining)>0)throw new HttpException(422,'Износот го надминува нераспределениот дел од буџетската ставка.');
            $allocationId=$this->db->insertId('INSERT INTO budget_allocations(budget_line_id,project_id,task_id,fiscal_year,period,planned_amount,current_amount,forecast_amount,created_at,updated_at) VALUES(:line,:project,NULL,:year,0,:amount,:amount2,:amount3,SYSUTCDATETIME(),SYSUTCDATETIME())',['line'=>$lineId,'project'=>$projectId,'year'=>(int)$line['fiscal_year'],'amount'=>$amount,'amount2'=>$amount,'amount3'=>$amount]);$this->audit->record($this->userId(),'allocation.project_linked','App\\Models\\BudgetAllocation',$allocationId,[],['project_id'=>$projectId,'budget_line_id'=>$lineId,'amount'=>$amount,'retroactive'=>$retroactive]);
            $commitmentId=null;if($retroactive){$reference=trim((string)$this->request->input('commitment_reference',''));if($reference==='')$reference='RETRO-'.$project['code'].'-'.$line['fiscal_year'].'-'.strtoupper(substr(str_replace('-','',Request::uuid()),0,8));if(mb_strlen($reference)>100)throw new HttpException(422,'Референцата на обврската е предолга.');if($this->db->scalar('SELECT TOP 1 1 FROM commitments WITH (UPDLOCK,HOLDLOCK) WHERE reference=:reference',['reference'=>$reference]))throw new HttpException(422,'Референцата на обврската веќе постои.');$supplier=trim((string)$this->request->input('supplier',''))?:('Ретроактивен проект · '.$project['name']);if(mb_strlen($supplier)>250)throw new HttpException(422,'Добавувачот / корисникот е предолг.');$date=trim((string)$this->request->input('committed_at',''));$date=$date!==''?$this->date('committed_at'):($project['starts_at']?:date('Y-m-d'));$commitmentId=$this->db->insertId("INSERT INTO commitments(budget_allocation_id,reservation_id,reference,supplier,amount,committed_at,status,created_by,created_at,updated_at) VALUES(:allocation,NULL,:reference,:supplier,:amount,:date,'active',:user,SYSUTCDATETIME(),SYSUTCDATETIME())",['allocation'=>$allocationId,'reference'=>$reference,'supplier'=>$supplier,'amount'=>$amount,'date'=>$date,'user'=>$this->userId()]);$this->transactionRow($allocationId,'commitment',$amount,'D','App\\Models\\Commitment',$commitmentId);$this->audit->record($this->userId(),'commitment.retroactive_posted','App\\Models\\Commitment',$commitmentId,[],['project_id'=>$projectId,'allocation_id'=>$allocationId,'amount'=>$amount,'reference'=>$reference]);}
            return['allocation'=>$allocationId,'commitment'=>$commitmentId];
        });
        $message='Распределба #'.$result['allocation'].' е создаден.'.($result['commitment']?' Ретроактивна обврска #'.$result['commitment'].' е објавено.':'');$this->redirect('/projects/'.$projectId,$message);
    }

    public function task(int $projectId): never
    {
        $project=$this->find('projects',$projectId);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true);$data=$this->taskData($projectId,(int)$project['directorate_id']);
        $id=$this->db->transaction(function()use($projectId,$data){$this->db->one('SELECT id FROM projects WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$projectId]);if($this->request->input('rebalance')==='1')$this->rebalanceTaskWeights($projectId,(float)$data['weight'],null);$this->assertTaskWeight($projectId,$data['weight'],null);$id=$this->db->insertId('INSERT INTO project_tasks(project_id,title,owner_id,starts_at,due_at,status,progress,weight_percent,next_step,has_budget_impact,dependency_notes,created_at,updated_at) VALUES(:project,:title,:owner,:starts,:due,:status,:progress,:weight,:next,0,:notes,SYSUTCDATETIME(),SYSUTCDATETIME())',['project'=>$projectId]+$data);$this->syncProjectProgress($projectId);$this->audit->record($this->userId(),'project_task.created','App\\Models\\ProjectTask',$id,[],$data+['project_id'=>$projectId]);return$id;});
        if($data['owner']!==null)$this->notifications->notify((int)$data['owner'],'project_task_assigned','Нова задача · #'.$id,['project_id'=>$projectId,'task_id'=>$id,'body'=>'Ви е доделена задачата '.$data['title'].'. Рок: '.($data['due']??'без рок').'.']);$uploads=$this->storeInitialEvidence($projectId,$id);$this->redirect('/projects/'.$projectId.($this->request->input('again')==='1'?'?tab=tasks&new_task=1':'?tab=tasks#task-'.$id),'Task-u “'.$data['title'].'“ е создадена.'.($uploads?' '.$uploads.' датотеки се ставени во карантин.':''));
    }

    public function taskUpdate(int $projectId,int $taskId): never
    {
        $project=$this->find('projects',$projectId);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true);$data=$this->taskData($projectId,(int)$project['directorate_id']);$token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9A-F]{16}$/i',$token))throw new HttpException(422,'Верзијата на задачата не е валидна. Освежете ја страницата.');
        $this->db->transaction(function()use($projectId,$taskId,$data,$token){$before=$this->db->one('SELECT *,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM project_tasks WITH (UPDLOCK,HOLDLOCK) WHERE id=:id AND project_id=:project',['id'=>$taskId,'project'=>$projectId]);if(!$before)throw new HttpException(404,'Задачата не е пронајдена.');if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Задачата е изменета од друг корисник. Освежете ја страницата.');if($this->request->input('rebalance')==='1')$this->rebalanceTaskWeights($projectId,(float)$data['weight'],$taskId);$this->assertTaskWeight($projectId,$data['weight'],$taskId);$this->db->execute('UPDATE project_tasks SET title=:title,owner_id=:owner,starts_at=:starts,due_at=:due,status=:status,progress=:progress,weight_percent=:weight,next_step=:next,dependency_notes=:notes,updated_at=SYSUTCDATETIME() WHERE id=:id',$data+['id'=>$taskId]);$this->syncProjectProgress($projectId);$auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'project_task.updated','App\\Models\\ProjectTask',$taskId,$auditBefore,$data);});
        $this->redirect('/projects/'.$projectId.'?tab=tasks#task-'.$taskId,'Задачата е ажурирана.');
    }

    public function progress(int $projectId): never
    {
        $project=$this->find('projects',$projectId);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true);
        $physical=$this->intField('physical_progress',0,100);$financial=trim((string)$this->request->input('financial_progress','0'));if(!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/',$financial)||((float)$financial)>100)throw new HttpException(422,'Финансискиот напредок не е валиден.');
        $data=['project'=>$projectId,'period'=>$this->date('period'),'progress'=>$this->intField('progress',0,100),'narrative'=>$this->requireField('narrative',5000),'blockers'=>trim((string)$this->request->input('blockers',''))?:null,'next'=>trim((string)$this->request->input('next_step',''))?:null,'user'=>$this->userId()];
        $id=$this->db->transaction(function()use($data,$physical,$financial,$projectId){$id=$this->db->insertId('INSERT INTO progress_updates (project_id,period,progress,narrative,blockers,next_step,created_by,created_at,updated_at) VALUES (:project,:period,:progress,:narrative,:blockers,:next,:user,SYSUTCDATETIME(),SYSUTCDATETIME())',$data);$this->syncProjectProgress((int)$data['project'],(int)$data['progress']);$this->audit->record($this->userId(),'project.progress_reported','App\\Models\\ProgressUpdate',$id,[],$data);$this->db->execute('UPDATE projects SET physical_progress=:physical,financial_progress=:financial,updated_at=SYSUTCDATETIME() WHERE id=:id',['physical'=>$physical,'financial'=>$financial,'id'=>$projectId]);$this->db->execute('UPDATE progress_updates SET physical_progress=:physical,financial_progress=:financial WHERE id=:id',['physical'=>$physical,'financial'=>$financial,'id'=>$id]);return $id;});

        $recipients=$this->db->all('SELECT DISTINCT u.id FROM users u JOIN projects p ON (p.manager_id=u.id OR p.sponsor_id=u.id) WHERE p.id=:id AND u.id<>:actor',['id'=>$projectId,'actor'=>$this->userId()]);foreach($recipients as $r)$this->notifications->notify((int)$r['id'],'project_progress_reported','Нов извештај · '.$project['code'].' · #'.$id,['project_id'=>$projectId,'progress_update_id'=>$id,'body'=>$this->auth->user()['name'].' известил за напредокот '.$data['progress'].'% за проектот '.$project['name'].'.']);
        $this->redirect('/projects/'.$projectId,'Извештајот е зачуван и објавен на панелот.');
    }

    public function allocation(): never
    {
        $line=$this->find('budget_lines',$this->intField('budget_line_id',1,PHP_INT_MAX));if(!$this->scope->canUseBudgetLine($this->userId(),(int)$line['id'],(int)$line['directorate_id'],'select'))throw new HttpException(403,'Буџетската ставка не е дозволена за избор од вашиот сектор.');
        $projectId=$this->request->integer('project_id')?:null;if($projectId){$project=$this->find('projects',$projectId);if(!(bool)$project['has_budget_impact']||!$this->scope->allowsFinancial($this->userId(),(int)$project['directorate_id']))throw new HttpException(422,'Проектот мора да има буџетско влијание и вие мора да имате финансиска улога за неговиот сектор.');$this->assertProjectCategory($project,$line);}elseif(!$this->scope->allowsFinancial($this->userId(),(int)$line['directorate_id']))throw new HttpException(422,'За делегирана ставка мора да се избере проект од вашиот сектор.');
        $planned=$this->nonNegativeAmount('planned_amount');$current=$this->nonNegativeAmount('current_amount');$forecast=trim((string)$this->request->input('forecast_amount',''))!==''?$this->nonNegativeAmount('forecast_amount'):$current;$data=['line'=>(int)$line['id'],'project'=>$projectId,'year'=>$this->intField('fiscal_year',2020,2100),'period'=>$this->intField('period',0,12),'planned'=>$planned,'current'=>$current,'forecast'=>$forecast];
        $id=$this->db->transaction(function()use($data){$locked=$this->db->one('SELECT * FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$data['line']]);if(!$locked)throw new HttpException(404,'Буџетската ставка не е пронајдена.');if($this->db->scalar('SELECT TOP 1 1 FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE budget_line_id=:line AND project_id'.($data['project']===null?' IS NULL':'=:project').' AND task_id IS NULL AND fiscal_year=:year AND period=:period',array_filter(['line'=>$data['line'],'project'=>$data['project'],'year'=>$data['year'],'period'=>$data['period']],static fn($v)=>$v!==null)))throw new HttpException(422,'Веќе постои распределба за оваа ставка, проект, година и период.');if(Money::compare($locked['total_amount']??'0','0')>0){$remaining=(string)$this->db->scalar('SELECT CAST(bl.total_amount-COALESCE((SELECT SUM(ba.current_amount) FROM budget_allocations ba WITH (UPDLOCK,HOLDLOCK) WHERE ba.budget_line_id=bl.id),0) AS decimal(19,4)) FROM budget_lines bl WHERE bl.id=:id',['id'=>$data['line']]);if(Money::compare($data['current'],$remaining)>0)throw new HttpException(422,'Распределбата го надминува одобрениот збир на буџетската ставка.');}$id=$this->db->insertId('INSERT INTO budget_allocations (budget_line_id,project_id,task_id,fiscal_year,period,planned_amount,current_amount,forecast_amount,created_at,updated_at) VALUES (:line,:project,NULL,:year,:period,:planned,:current,:forecast,SYSUTCDATETIME(),SYSUTCDATETIME())',$data);$this->audit->record($this->userId(),'allocation.created','App\\Models\\BudgetAllocation',$id,[],$data);return$id;});$this->redirect('/budget','Распределбата е создадена.');
    }

    public function budgetLine(): never
    {
        $directorate=$this->intField('directorate_id',1,PHP_INT_MAX);if(!$this->scope->allowsFinancial($this->userId(),$directorate))throw new HttpException(403,'Немате финансиска улога за овој сектор.');$year=$this->intField('fiscal_year',2020,2100);$code=$this->requireField('project_code',60);$name=$this->requireField('name',250);$category=$this->requireField('budget_category',40);$economic=['goods_services'=>'MALLRA_SHERBIME','capital_investments'=>'INVESTIME_KAPITALE','wages'=>'PAGA','subsidies'=>'SUBVENCIONE','utilities'=>'SHPENZIME_KOMUNALE'];if(!isset($economic[$category]))throw new HttpException(422,'Буџетската категорија не е валидна.');$own=$this->nonNegativeAmount('own_revenue');$government=$this->nonNegativeAmount('government_grant');$health=$this->nonNegativeAmount('health_grant');$education=$this->nonNegativeAmount('education_grant');$loan=$this->nonNegativeAmount('loan_financing');$carryover=$this->nonNegativeAmount('carryover_revenue');if((float)$own+(float)$government+(float)$health+(float)$education+(float)$loan+(float)$carryover<=0)throw new HttpException(422,'Збирот на буџетската ставка мора да биде поголем од нула.');
        $id=$this->db->transaction(function()use($directorate,$year,$code,$name,$category,$economic,$own,$government,$health,$education,$loan,$carryover){$cycle=$this->db->one('SELECT TOP 1 id FROM budget_cycles WITH (UPDLOCK,HOLDLOCK) WHERE base_year=:year AND name=:name',['year'=>$year,'name'=>'Буџет одобрен од Советот на општината']);if(!$cycle){$cycleId=$this->db->insertId("INSERT INTO budget_cycles(name,base_year,horizon,status,starts_at,ends_at,created_at,updated_at) VALUES(:name,:year,1,'approved',:starts,:ends,SYSUTCDATETIME(),SYSUTCDATETIME())",['name'=>'Буџет одобрен од Советот на општината','year'=>$year,'starts'=>$year.'-01-01','ends'=>$year.'-12-31']);}else$cycleId=(int)$cycle['id'];$version=$this->db->one("SELECT TOP 1 id FROM budget_versions WITH (UPDLOCK,HOLDLOCK) WHERE budget_cycle_id=:cycle AND scenario='approved' AND is_official=1 ORDER BY version_no DESC",['cycle'=>$cycleId]);if(!$version)$versionId=$this->db->insertId("INSERT INTO budget_versions(budget_cycle_id,scenario,version_no,status,is_official,approved_at,approved_by,created_at,updated_at) VALUES(:cycle,'approved',1,'approved',1,SYSUTCDATETIME(),:user,SYSUTCDATETIME(),SYSUTCDATETIME())",['cycle'=>$cycleId,'user'=>$this->userId()]);else$versionId=(int)$version['id'];if($this->db->scalar("SELECT TOP 1 1 FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE budget_version_id=:version AND directorate_id=:directorate AND code=:code AND funding_source='combined'",['version'=>$versionId,'directorate'=>$directorate,'code'=>$code]))throw new HttpException(422,'Оваа ставка веќе постои за избраниот сектор и година.');$id=$this->db->insertId("INSERT INTO budget_lines(budget_version_id,directorate_id,program_id,code,name,economic_code,funding_source,budget_category,own_revenue,government_grant,health_grant,education_grant,loan_financing,carryover_revenue,currency,created_at,updated_at) VALUES(:version,:directorate,NULL,:code,:name,:economic,'combined',:category,:own,:government,:health,:education,:loan,:carryover,'MKD',SYSUTCDATETIME(),SYSUTCDATETIME())",['version'=>$versionId,'directorate'=>$directorate,'code'=>$code,'name'=>$name,'economic'=>$economic[$category],'category'=>$category,'own'=>$own,'government'=>$government,'health'=>$health,'education'=>$education,'loan'=>$loan,'carryover'=>$carryover]);$this->audit->record($this->userId(),'budget_line.approved_created','App\\Models\\BudgetLine',$id,[],['fiscal_year'=>$year,'directorate_id'=>$directorate,'project_code'=>$code,'name'=>$name,'category'=>$category,'own_revenue'=>$own,'government_grant'=>$government,'health_grant'=>$health,'education_grant'=>$education,'loan_financing'=>$loan]);return$id;});$this->redirect('/budget','Одобрена буџетска ставка #'.$id.' е евидентиран.');
    }

    public function budgetLineUpdate(int$id): never
    {
        $code=$this->requireField('project_code',60);$name=$this->requireField('name',250);$category=$this->requireField('budget_category',40);$economic=['goods_services'=>'MALLRA_SHERBIME','capital_investments'=>'INVESTIME_KAPITALE','wages'=>'PAGA','subsidies'=>'SUBVENCIONE','utilities'=>'SHPENZIME_KOMUNALE'];if(!isset($economic[$category]))throw new HttpException(422,'Буџетската категорија не е валидна.');$own=$this->nonNegativeAmount('own_revenue');$government=$this->nonNegativeAmount('government_grant');$health=$this->nonNegativeAmount('health_grant');$education=$this->nonNegativeAmount('education_grant');$loan=$this->nonNegativeAmount('loan_financing');$carryover=$this->nonNegativeAmount('carryover_revenue');$total=(float)$own+(float)$government+(float)$health+(float)$education+(float)$loan+(float)$carryover;if($total<=0)throw new HttpException(422,'Збирот мора да биде поголем од нула.');$token=$this->requireField('updated_at',23);
        $this->db->transaction(function()use($id,$code,$name,$category,$economic,$own,$government,$health,$education,$loan,$carryover,$total,$token){$before=$this->db->one('SELECT bl.*,CONVERT(varchar(23),bl.updated_at,121) updated_at_token,bv.is_official FROM budget_lines bl WITH (UPDLOCK,HOLDLOCK) JOIN budget_versions bv ON bv.id=bl.budget_version_id WHERE bl.id=:id',['id'=>$id]);if(!$before||!(bool)$before['is_official']||$before['budget_category']===null)throw new HttpException(404,'Одобрената буџетска ставка не е пронајдена.');if(!$this->scope->allowsFinancial($this->userId(),(int)$before['directorate_id']))throw new HttpException(403,'Немате финансиска улога за овој сектор.');if(!hash_equals((string)$before['updated_at_token'],$token))throw new HttpException(409,'Ставката е изменета од друг корисник. Освежете ја страницата.');$allocated=(float)$this->db->scalar('SELECT COALESCE(SUM(current_amount),0) FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE budget_line_id=:id',['id'=>$id]);if($total+0.00001<$allocated)throw new HttpException(422,'Збирот не може да биде помал од моментално распределениот износ ('.number_format($allocated,2).' ден.).');if($this->db->scalar('SELECT TOP 1 1 FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE budget_version_id=:version AND directorate_id=:directorate AND code=:code AND funding_source=:source AND id<>:id',['version'=>(int)$before['budget_version_id'],'directorate'=>(int)$before['directorate_id'],'code'=>$code,'source'=>'combined','id'=>$id]))throw new HttpException(422,'Постои друга ставка со оваа шифра.');$this->db->execute('UPDATE budget_lines SET code=:code,name=:name,economic_code=:economic,budget_category=:category,own_revenue=:own,government_grant=:government,health_grant=:health,education_grant=:education,loan_financing=:loan,carryover_revenue=:carryover,updated_at=SYSUTCDATETIME() WHERE id=:id',['code'=>$code,'name'=>$name,'economic'=>$economic[$category],'category'=>$category,'own'=>$own,'government'=>$government,'health'=>$health,'education'=>$education,'loan'=>$loan,'carryover'=>$carryover,'id'=>$id]);$auditBefore=$before;unset($auditBefore['updated_at_token'],$auditBefore['is_official']);$this->audit->record($this->userId(),'budget_line.approved_updated','App\\Models\\BudgetLine',$id,$auditBefore,['project_code'=>$code,'name'=>$name,'category'=>$category,'own_revenue'=>$own,'government_grant'=>$government,'health_grant'=>$health,'education_grant'=>$education,'loan_financing'=>$loan,'carryover_revenue'=>$carryover,'total'=>$total]);});$this->redirect('/budget','Буџетската ставка е ажурирана.');
    }

    public function budgetLineAccess(int$id): never
    {
        $selectIds=$this->postedIds('select_directorate_ids');$reportIds=$this->postedIds('report_directorate_ids');$allIds=array_values(array_unique(array_merge($selectIds,$reportIds)));
        $this->db->transaction(function()use($id,$selectIds,$reportIds,$allIds){
            $line=$this->db->one('SELECT id,directorate_id,code,name FROM budget_lines WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$line)throw new HttpException(404,'Буџетската ставка не е пронајдена.');if(!$this->scope->allowsFinancial($this->userId(),(int)$line['directorate_id']))throw new HttpException(403,'Само секторот сопственик може да го определи пристапот до ставката.');if(in_array((int)$line['directorate_id'],$allIds,true))throw new HttpException(422,'Секторот сопственик не се додава како делегиран пристап.');
            if($allIds){$params=[];$marks=[];foreach($allIds as$i=>$directorateId){$key='directorate'.$i;$marks[]=':'.$key;$params[$key]=$directorateId;}$valid=(int)$this->db->scalar('SELECT COUNT(*) FROM directorates WHERE active=1 AND id IN ('.implode(',',$marks).')',$params);if($valid!==count($allIds))throw new HttpException(422,'Еден или повеќе сектори не се активни или не постојат.');}
            $before=$this->db->all('SELECT directorate_id,can_select,can_report FROM budget_line_directorate_access WHERE budget_line_id=:line ORDER BY directorate_id',['line'=>$id]);$this->db->execute('DELETE FROM budget_line_directorate_access WHERE budget_line_id=:line',['line'=>$id]);foreach($allIds as$directorateId)$this->db->execute('INSERT INTO budget_line_directorate_access(budget_line_id,directorate_id,can_select,can_report,granted_by,created_at,updated_at) VALUES(:line,:directorate,:can_select,:can_report,:user,SYSUTCDATETIME(),SYSUTCDATETIME())',['line'=>$id,'directorate'=>$directorateId,'can_select'=>in_array($directorateId,$selectIds,true)?1:0,'can_report'=>in_array($directorateId,$reportIds,true)?1:0,'user'=>$this->userId()]);$this->db->execute('UPDATE budget_lines SET updated_at=SYSUTCDATETIME() WHERE id=:id',['id'=>$id]);$after=$this->db->all('SELECT directorate_id,can_select,can_report FROM budget_line_directorate_access WHERE budget_line_id=:line ORDER BY directorate_id',['line'=>$id]);$this->audit->record($this->userId(),'budget_line.directorate_access_updated','App\\Models\\BudgetLine',$id,['access'=>$before],['access'=>$after]);
        });
        $this->redirect('/budget/lines/'.$id.'/edit','Пристапот на секторите е ажуриран.');
    }

    public function commitment(): never
    {
        $allocationId=$this->intField('budget_allocation_id',1,PHP_INT_MAX);$amount=$this->amount('amount');$reference=$this->requireField('reference',100);$supplier=$this->requireField('supplier',250);$date=$this->date('committed_at');
        $id=$this->db->transaction(function()use($allocationId,$amount,$reference,$supplier,$date){$a=$this->lockedAllocation($allocationId);$this->assertFinancial($a);if($this->db->scalar('SELECT TOP 1 1 FROM commitments WITH (UPDLOCK,HOLDLOCK) WHERE reference=:reference',['reference'=>$reference]))throw new HttpException(422,'Референцата на обврската веќе постои.');$available=$this->available($allocationId);if(Money::compare($amount,$available)>0)throw new HttpException(422,'Износот го надминува расположивиот фонд ('.$available.' ден.).');$id=$this->db->insertId("INSERT INTO commitments (budget_allocation_id,reservation_id,reference,supplier,amount,committed_at,status,created_by,created_at,updated_at) VALUES (:a,NULL,:r,:s,:amount,:date,'active',:u,SYSUTCDATETIME(),SYSUTCDATETIME())",['a'=>$allocationId,'r'=>$reference,'s'=>$supplier,'amount'=>$amount,'date'=>$date,'u'=>$this->userId()]);$this->transactionRow($allocationId,'commitment',$amount,'D','App\\Models\\Commitment',$id);$this->audit->record($this->userId(),'commitment.posted','App\\Models\\Commitment',$id,[],['amount'=>$amount,'reference'=>$reference]);return $id;});
        $this->redirect('/execution','Обврска #'.$id.' е објавено.');
    }

    public function expenditure(): never
    {
        $allocationId=$this->intField('budget_allocation_id',1,PHP_INT_MAX);$commitmentId=$this->intField('commitment_id',1,PHP_INT_MAX);$amount=$this->amount('amount');$reference=$this->requireField('source_reference',120);$date=$this->date('accounting_date');
        $id=$this->db->transaction(function()use($allocationId,$commitmentId,$amount,$reference,$date){$a=$this->lockedAllocation($allocationId);$this->assertFinancial($a);$c=$this->db->one('SELECT * FROM commitments WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$commitmentId]);if(!$c||(int)$c['budget_allocation_id']!==$allocationId)throw new HttpException(422,'Обврската не припаѓа на оваа распределба.');if($c['status']!=='active')throw new HttpException(422,'Обврската не е активна.');if($this->db->scalar('SELECT TOP 1 1 FROM expenditures WHERE source_reference=:reference',['reference'=>$reference]))throw new HttpException(422,'Изворната референца е веќе објавена.');$remaining=(string)$this->db->scalar('SELECT CAST(c.amount-COALESCE((SELECT SUM(e.amount) FROM expenditures e WITH (UPDLOCK,HOLDLOCK) WHERE e.commitment_id=c.id),0) AS decimal(19,4)) FROM commitments c WHERE c.id=:id',['id'=>$commitmentId]);if(Money::compare($amount,$remaining)>0)throw new HttpException(422,'Расходот го надминува нереализираниот дел од обврската.');$id=$this->db->insertId('INSERT INTO expenditures (commitment_id,budget_allocation_id,source_reference,amount,accounting_date,created_by,created_at,updated_at) VALUES (:c,:a,:r,:amount,:date,:u,SYSUTCDATETIME(),SYSUTCDATETIME())',['c'=>$commitmentId,'a'=>$allocationId,'r'=>$reference,'amount'=>$amount,'date'=>$date,'u'=>$this->userId()]);$this->transactionRow($allocationId,'expenditure',$amount,'D','App\\Models\\Expenditure',$id);$this->audit->record($this->userId(),'expenditure.posted','App\\Models\\Expenditure',$id,[],['amount'=>$amount,'source_reference'=>$reference]);return $id;});
        $this->redirect('/execution','Расход #'.$id.' е објавено.');
    }

    public function change(): never
    {
        $allowed=['transfer','reallocation','increase','decrease','carryover','cancellation','technical_correction'];$type=$this->requireField('type',30);if(!in_array($type,$allowed,true))throw new HttpException(422,'Видот не е валиден.');$sourceId=$this->intField('source_allocation_id',1,PHP_INT_MAX);$targetId=$this->intField('target_allocation_id',1,PHP_INT_MAX);if($sourceId===$targetId)throw new HttpException(422,'Изворот и целта мора да бидат различни.');$source=$this->allocationWithLine($sourceId);$target=$this->allocationWithLine($targetId);$this->assertFinancial($source);$this->assertFinancial($target);$justification=$this->requireField('justification');if(mb_strlen($justification)<20)throw new HttpException(422,'Образложението мора да има најмалку 20 знаци.');$urgency=$this->requireField('urgency',20);if(!in_array($urgency,['normal','high','critical'],true))throw new HttpException(422,'Итноста не е валидна.');
        $changeId=$this->db->transaction(function()use($type,$sourceId,$targetId,$justification,$urgency,$source){$data=['type'=>$type,'source'=>$sourceId,'target'=>$targetId,'amount'=>$this->amount('amount'),'year'=>$this->intField('fiscal_year',1900,2200),'justification'=>$justification,'impact'=>trim((string)$this->request->input('impact',''))?:null,'urgency'=>$urgency,'user'=>$this->userId()];$id=$this->db->insertId("INSERT INTO budget_change_requests (type,source_allocation_id,target_allocation_id,amount,fiscal_year,justification,impact,urgency,workflow_status,requested_by,created_at,updated_at) VALUES (:type,:source,:target,:amount,:year,:justification,:impact,:urgency,'submitted',:user,SYSUTCDATETIME(),SYSUTCDATETIME())",$data);$workflow=$this->db->insertId("INSERT INTO workflow_instances (directorate_id,entity_type,entity_id,workflow_type,state,initiated_by,created_at,updated_at) VALUES (:d,:type,:id,'budget_change','submitted',:u,SYSUTCDATETIME(),SYSUTCDATETIME())",['d'=>$source['operational_directorate_id'],'type'=>'App\\Models\\BudgetChangeRequest','id'=>$id,'u'=>$this->userId()]);foreach([1=>'mayor_admin',2=>'finance',3=>'mayor']as$seq=>$role)$this->db->execute("INSERT INTO workflow_steps (workflow_instance_id,sequence,required_role,state,due_at,created_at,updated_at) VALUES (:w,:seq,:role,:state,DATEADD(day,5,SYSUTCDATETIME()),SYSUTCDATETIME(),SYSUTCDATETIME())",['w'=>$workflow,'seq'=>$seq,'role'=>$role,'state'=>$seq===1?'active':'pending']);$this->audit->record($this->userId(),'budget_change.submitted','App\\Models\\BudgetChangeRequest',$id,[],$data);return $id;});$this->redirect('/changes','Барање #'.$changeId.' е поднесено во работниот тек.');
    }

    public function approveChange(int $id): never
    {
        $roles=$this->scope->roles($this->userId());if(!array_intersect($roles,['mayor','finance','system_admin']))throw new HttpException(403,'Немате улога за одобрување.');
        $posted=$this->db->transaction(function()use($id){
            $workflow=$this->db->one("SELECT id,state FROM workflow_instances WITH (UPDLOCK,HOLDLOCK) WHERE entity_type=:t AND entity_id=:id",['t'=>'App\\Models\\BudgetChangeRequest','id'=>$id]);
            // Requests with a workflow are posted automatically when the last step is approved; direct posting is only for legacy requests.
            if($workflow&&$workflow['state']!=='approved')throw new HttpException(422,'Барањето мора да се одобри во сите чекори од работниот тек пред објавувањето.');
            return $this->postBudgetChange($id);
        });
        $this->redirect('/changes',$posted?'Промената е атомски објавена.':'Промената веќе беше објавена.');
    }

    public function resubmitChange(int $id): never
    {
        $this->db->transaction(function()use($id){
            $change=$this->db->one('SELECT * FROM budget_change_requests WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$change)throw new HttpException(404,'Барањето не е пронајдено.');
            if((int)$change['requested_by']!==$this->userId())throw new HttpException(403,'Само барателот може повторно да го поднесе барањето.');
            $workflow=$this->db->one("SELECT * FROM workflow_instances WITH (UPDLOCK,HOLDLOCK) WHERE entity_type=:t AND entity_id=:id",['t'=>'App\\Models\\BudgetChangeRequest','id'=>$id]);
            if(!$workflow||$workflow['state']!=='returned')throw new HttpException(422,'Само вратените барања можат повторно да се поднесат.');
            $this->db->execute("UPDATE workflow_steps SET state=CASE WHEN sequence=(SELECT MIN(sequence) FROM workflow_steps WHERE workflow_instance_id=:w1) THEN 'active' ELSE 'pending' END,decision=NULL,decided_at=NULL,decided_by=NULL,due_at=DATEADD(day,5,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE workflow_instance_id=:w2",['w1'=>$workflow['id'],'w2'=>$workflow['id']]);
            $this->db->execute("UPDATE workflow_instances SET state='submitted',completed_at=NULL,updated_at=SYSUTCDATETIME() WHERE id=:id",['id'=>$workflow['id']]);
            $this->db->execute("UPDATE budget_change_requests SET workflow_status='submitted',updated_at=SYSUTCDATETIME() WHERE id=:id",['id'=>$id]);
            $this->audit->record($this->userId(),'budget_change.resubmitted','App\\Models\\BudgetChangeRequest',$id,['workflow_status'=>$change['workflow_status']],['workflow_status'=>'submitted']);
        });
        $this->redirect('/changes','Барањето е повторно поднесено во работниот тек.');
    }

    /** Moves the amount between allocations and writes the D/C ledger rows. Must run inside a transaction. Returns false when already posted. */
    private function postBudgetChange(int $id): bool
    {
        $change=$this->db->one('SELECT * FROM budget_change_requests WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$change)throw new HttpException(404,'Барањето не е пронајдено.');
        if($change['workflow_status']==='approved'&&$change['approved_at']!==null)return false;
        if(in_array($change['workflow_status'],['rejected'],true))throw new HttpException(422,'Барањето е одбиено.');
        $ids=[(int)$change['source_allocation_id'],(int)$change['target_allocation_id']];sort($ids);foreach($ids as $allocationId)$this->lockedAllocation($allocationId);
        $available=$this->available((int)$change['source_allocation_id']);if(Money::compare((string)$change['amount'],(string)$available)>0)throw new HttpException(422,'Промената би создала негативно салдо во изворот.');
        $this->db->execute('UPDATE budget_allocations SET current_amount=current_amount-:amount,updated_at=SYSUTCDATETIME() WHERE id=:id',['amount'=>$change['amount'],'id'=>(int)$change['source_allocation_id']]);
        $this->db->execute('UPDATE budget_allocations SET current_amount=current_amount+:amount,updated_at=SYSUTCDATETIME() WHERE id=:id',['amount'=>$change['amount'],'id'=>(int)$change['target_allocation_id']]);
        $this->transactionRow((int)$change['source_allocation_id'],'budget_change',(string)$change['amount'],'D','App\\Models\\BudgetChangeRequest',$id,Request::uuid());
        $this->transactionRow((int)$change['target_allocation_id'],'budget_change',(string)$change['amount'],'C','App\\Models\\BudgetChangeRequest',$id,Request::uuid());
        $this->db->execute("UPDATE budget_change_requests SET workflow_status='approved',approved_by=:u,approved_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id",['u'=>$this->userId(),'id'=>$id]);
        $this->db->execute("UPDATE workflow_instances SET state='approved',completed_at=COALESCE(completed_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE entity_type=:t AND entity_id=:id",['t'=>'App\\Models\\BudgetChangeRequest','id'=>$id]);
        $this->audit->record($this->userId(),'budget_change.approved','App\\Models\\BudgetChangeRequest',$id,$change,['workflow_status'=>'approved']);
        return true;
    }

    public function workflow(): never
    {
        $id=$this->intField('workflow_id',1,PHP_INT_MAX);$action=$this->requireField('action',20);if(!in_array($action,['approve','return','reject'],true))throw new HttpException(422,'Дејството не е валидно.');$comment=trim((string)$this->request->input('comment',''))?:null;if(in_array($action,['return','reject'],true)&&$comment===null)throw new HttpException(422,'Коментарот е задолжителен.');
        $message=$this->db->transaction(function()use($id,$action,$comment){
            $workflow=$this->db->one('SELECT * FROM workflow_instances WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$workflow)throw new HttpException(404,'Работниот тек не е пронајден.');
            if(!in_array($workflow['state'],['submitted','in_review'],true))throw new HttpException(422,'Овој работен тек е затворен или чека повторно поднесување.');
            if($workflow['directorate_id']!==null&&!$this->scope->allowsDirectorate($this->userId(),(int)$workflow['directorate_id']))throw new HttpException(403,'Надвор од вашиот опсег.');
            $step=$this->db->one("SELECT TOP 1 * FROM workflow_steps WITH (UPDLOCK,HOLDLOCK) WHERE workflow_instance_id=:id AND state='active' ORDER BY sequence",['id'=>$id]);if(!$step)throw new HttpException(422,'Нема активен чекор.');
            if(!$this->scope->isSystemAdmin($this->userId())&&!$this->scope->satisfiesWorkflowRole($this->userId(),(string)$step['required_role']))throw new HttpException(403,'Вашата улога не може да одлучува за овој чекор.');
            $this->db->execute("UPDATE workflow_steps SET state='completed',decision=:a,comment=:c,decided_at=SYSUTCDATETIME(),decided_by=:u,updated_at=SYSUTCDATETIME() WHERE id=:id",['a'=>$action,'c'=>$comment,'u'=>$this->userId(),'id'=>$step['id']]);
            $isChange=$workflow['entity_type']==='App\\Models\\BudgetChangeRequest';$message='Одлуката е евидентирана.';
            if($action==='approve'){
                $next=$this->db->one('SELECT TOP 1 id FROM workflow_steps WHERE workflow_instance_id=:w AND sequence>:seq ORDER BY sequence',['w'=>$id,'seq'=>$step['sequence']]);
                if($next)$this->db->execute("UPDATE workflow_steps SET state='active',updated_at=SYSUTCDATETIME() WHERE id=:id",['id'=>$next['id']]);
                elseif($isChange){$this->postBudgetChange((int)$workflow['entity_id']);$message='Последниот чекор е одобрен и буџетската промена е објавена.';}
                else$this->db->execute("UPDATE workflow_instances SET state='approved',completed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id",['id'=>$id]);
            }else{
                $state=$action==='return'?'returned':'rejected';
                $this->db->execute("UPDATE workflow_instances SET state=:state,completed_at=".($action==='reject'?'SYSUTCDATETIME()':'NULL').",updated_at=SYSUTCDATETIME() WHERE id=:id",['state'=>$state,'id'=>$id]);
                if($isChange)$this->db->execute('UPDATE budget_change_requests SET workflow_status=:state,updated_at=SYSUTCDATETIME() WHERE id=:id',['state'=>$state,'id'=>(int)$workflow['entity_id']]);
            }
            $this->audit->record($this->userId(),'workflow.'.$action,'App\\Models\\WorkflowInstance',$id,$workflow,['state'=>$action],$comment);
            return$message;
        });
        $this->redirect('/workflows',$message);
    }

    public function risk(): never
    {
        $d=$this->intField('directorate_id',1,PHP_INT_MAX);if(!$this->scope->allowsDirectorate($this->userId(),$d))throw new HttpException(403,'Надвор од вашиот опсег.');$project=$this->request->integer('project_id')?:null;$this->assertRiskProject($project,$d);$category=$this->riskCategory();$probability=$this->intField('probability',1,5);$impact=$this->intField('impact',1,5);$response=$this->requireField('response',20);if(!in_array($response,['avoid','reduce','transfer','accept'],true))throw new HttpException(422,'Одговорот не е валиден.');$data=['d'=>$d,'project'=>$project,'category'=>$category,'description'=>$this->requireField('description',10000),'cause'=>trim((string)$this->request->input('cause',''))?:null,'consequence'=>trim((string)$this->request->input('consequence',''))?:null,'owner'=>$this->userId(),'probability'=>$probability,'impact'=>$impact,'score'=>$probability*$impact,'residual_probability'=>$probability,'residual_impact'=>$impact,'residual_score'=>$probability*$impact,'response'=>$response,'review'=>$this->date('review_at',false)];$id=$this->db->insertId("INSERT INTO risks (directorate_id,project_id,category,description,cause,consequence,owner_id,probability,impact,inherent_score,response,residual_score,residual_probability,residual_impact,review_at,status,created_at,updated_at) VALUES (:d,:project,:category,:description,:cause,:consequence,:owner,:probability,:impact,:score,:response,:residual_score,:residual_probability,:residual_impact,:review,'open',SYSUTCDATETIME(),SYSUTCDATETIME())",$data);if($data['score']>=15){$recipients=$this->db->all("SELECT DISTINCT urs.user_id FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");foreach($recipients as$r)$this->notifications->notify((int)$r['user_id'],'critical_risk','Критичен ризик · #'.$id,['risk_id'=>$id,'score'=>$data['score'],'body'=>'Евидентиран е критичен ризик со резултат '.$data['score'].'.']);}$this->audit->record($this->userId(),'risk.created','App\\Models\\Risk',$id,[],$data);$this->redirect('/risks','Ризикот е евидентиран.');
    }

    public function riskUpdate(int $id): never
    {
        $category=$this->requireField('category',60);$project=$this->request->integer('project_id')?:null;$description=$this->requireField('description',10000);$cause=trim((string)$this->request->input('cause',''))?:null;$consequence=trim((string)$this->request->input('consequence',''))?:null;if(($cause!==null&&mb_strlen($cause)>10000)||($consequence!==null&&mb_strlen($consequence)>10000))throw new HttpException(422,'Причината или последицата е предолга.');
        $probability=$this->intField('probability',1,5);$impact=$this->intField('impact',1,5);$score=$probability*$impact;$response=$this->requireField('response',20);if(!in_array($response,['avoid','reduce','transfer','accept'],true))throw new HttpException(422,'Одговорот не е валиден.');$status=$this->requireField('status',20);$review=$this->date('review_at',false);$token=$this->requireField('updated_at',23);$becameCritical=false;
        $this->db->transaction(function()use($id,$project,$category,$description,$cause,$consequence,$probability,$impact,$score,$response,$status,$review,$token,&$becameCritical){
            $before=$this->db->one('SELECT id,directorate_id,project_id,category,description,cause,consequence,owner_id,probability,impact,inherent_score,response,residual_score,CONVERT(varchar(10),review_at,23) review_at,status,CONVERT(varchar(23),updated_at,121) updated_at_token FROM risks WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Ризикот не е пронајден.');if(!$this->scope->allowsDirectorate($this->userId(),(int)$before['directorate_id']))throw new HttpException(403,'Надвор од вашиот опсег.');if(!hash_equals((string)$before['updated_at_token'],$token))throw new HttpException(409,'Ризикот е изменет од друг корисник. Освежете ја страницата.');
            if(!in_array($status,['open','monitoring','mitigated','closed'],true)&&$status!==$before['status'])throw new HttpException(422,'Статусот на ризикот не е валиден.');$this->assertRiskCategory($category,(string)$before['category']);
            $this->assertRiskProject($project,(int)$before['directorate_id']);[$residualProbability,$residualImpact,$residualScore]=$this->riskResidual($id,$probability,$impact);$this->db->execute('UPDATE risks SET project_id=:project,category=:category,description=:description,cause=:cause,consequence=:consequence,probability=:probability,impact=:impact,inherent_score=:score,residual_probability=:residual_probability,residual_impact=:residual_impact,residual_score=:residual_score,response=:response,review_at=:review,status=:status,updated_at=SYSUTCDATETIME() WHERE id=:id',['project'=>$project,'category'=>$category,'description'=>$description,'cause'=>$cause,'consequence'=>$consequence,'probability'=>$probability,'impact'=>$impact,'score'=>$score,'residual_probability'=>$residualProbability,'residual_impact'=>$residualImpact,'residual_score'=>$residualScore,'response'=>$response,'review'=>$review,'status'=>$status,'id'=>$id]);
            $after=['project_id'=>$project,'category'=>$category,'description'=>$description,'cause'=>$cause,'consequence'=>$consequence,'probability'=>$probability,'impact'=>$impact,'inherent_score'=>$score,'residual_probability'=>$residualProbability,'residual_impact'=>$residualImpact,'residual_score'=>$residualScore,'response'=>$response,'review_at'=>$review,'status'=>$status];$auditBefore=$before;unset($auditBefore['updated_at_token']);$this->audit->record($this->userId(),'risk.updated','App\\Models\\Risk',$id,$auditBefore,$after);$becameCritical=(int)$before['inherent_score']<15&&$score>=15;
        });
        if($becameCritical){$recipients=$this->db->all("SELECT DISTINCT urs.user_id FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");foreach($recipients as$r)$this->notifications->notify((int)$r['user_id'],'critical_risk','Критичен ризик по ажурирањето · #'.$id,['risk_id'=>$id,'score'=>$score,'body'=>'Ризик #'.$id.' е ажуриран со критичен резултат '.$score.'.']);}
        $this->redirect('/risks','Ризикот е успешно ажуриран.');
    }

    public function riskMeasure(int $riskId): never
    {
        $risk=$this->db->one('SELECT id,directorate_id FROM risks WHERE id=:id',['id'=>$riskId]);if(!$risk)throw new HttpException(404,'Ризикот не е пронајден.');if(!$this->scope->allowsDirectorate($this->userId(),(int)$risk['directorate_id']))throw new HttpException(403,'Надвор од вашиот опсег.');$data=$this->riskMeasureData((int)$risk['directorate_id']);
        $id=$this->db->transaction(function()use($riskId,$data){$risk=$this->db->one('SELECT id FROM risks WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$riskId]);if(!$risk)throw new HttpException(404,'Ризикот не е пронајден.');$id=$this->db->insertId("INSERT INTO risk_actions(risk_id,action,measure_type,owner_id,due_at,cost,status,probability_reduction,impact_reduction,evidence,completed_at,created_by,created_at,updated_at) VALUES(:risk,:action,:type,:owner,:due,:cost,:status,:probability_reduction,:impact_reduction,:evidence,CASE WHEN :status_check='completed' THEN SYSUTCDATETIME() ELSE NULL END,:created_by,SYSUTCDATETIME(),SYSUTCDATETIME())",['risk'=>$riskId]+$data+['status_check'=>$data['status'],'created_by'=>$this->userId()]);$residual=$this->syncRiskResidual($riskId);$this->audit->record($this->userId(),'risk_measure.created','App\\Models\\RiskAction',$id,[],$data+['risk_id'=>$riskId,'residual'=>$residual]);return$id;});$this->redirect('/risks/'.$riskId.'/edit','Мерка #'.$id.' е додадена и резидуалниот ризик е повторно пресметан.');
    }

    public function riskMeasureUpdate(int $riskId,int $measureId): never
    {
        $risk=$this->db->one('SELECT id,directorate_id FROM risks WHERE id=:id',['id'=>$riskId]);if(!$risk)throw new HttpException(404,'Ризикот не е пронајден.');if(!$this->scope->allowsDirectorate($this->userId(),(int)$risk['directorate_id']))throw new HttpException(403,'Надвор од вашиот опсег.');$data=$this->riskMeasureData((int)$risk['directorate_id'],true);$token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9A-F]{16}$/i',$token))throw new HttpException(422,'Верзијата на мерката не е валидна. Освежете ја страницата.');
        $this->db->transaction(function()use($riskId,$measureId,$data,$token){$this->db->one('SELECT id FROM risks WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$riskId]);$before=$this->db->one('SELECT *,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM risk_actions WITH (UPDLOCK,HOLDLOCK) WHERE id=:id AND risk_id=:risk',['id'=>$measureId,'risk'=>$riskId]);if(!$before)throw new HttpException(404,'Мерката не е пронајдена.');if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Мерката е изменета од друг корисник. Освежете ја страницата.');$this->db->execute("UPDATE risk_actions SET action=:action,measure_type=:type,owner_id=:owner,due_at=:due,cost=:cost,status=:status,probability_reduction=:probability_reduction,impact_reduction=:impact_reduction,evidence=:evidence,completed_at=CASE WHEN :status_check='completed' THEN COALESCE(completed_at,SYSUTCDATETIME()) ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",$data+['status_check'=>$data['status'],'id'=>$measureId]);$residual=$this->syncRiskResidual($riskId);$auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'risk_measure.updated','App\\Models\\RiskAction',$measureId,$auditBefore,$data+['risk_id'=>$riskId,'residual'=>$residual]);});$this->redirect('/risks/'.$riskId.'/edit','Мерката е ажурирана и резидуалниот ризик е повторно пресметан.');
    }

    public function importPreview(): never
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException(422, $this->uploadError($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE));
        }
        $type = $this->requireField('import_type', 50);
        if (!in_array($type, ImportWorkflow::types(), true)) throw new HttpException(422, 'Видот на увозот не е валиден.');
        if ($type === ImportWorkflow::ENFORCEMENT_EXECUTIONS && !$this->scope->canImportExecutedCases($this->userId())) throw new HttpException(403, 'Само службениците од Финансии можат да ја увезат табелата на извршувања.');
        if ($type === ImportWorkflow::FREEBALANCE_SUMMARY && !$this->scope->canImportBudgetExecution($this->userId())) throw new HttpException(403, 'Само службениците од Финансии можат да го увезат FreeBalance извештајот.');
        $options = [];
        if ($type === ImportWorkflow::FREEBALANCE_SUMMARY) $options['as_of_date'] = (string) $this->date('as_of_date');
        if ($type === ImportWorkflow::APPROVED_BUDGET) {
            if (!$this->scope->canImportBudgetExecution($this->userId())) throw new HttpException(403, 'Само службениците од Финансии можат да го увезат одобрениот буџет.');
            $options['fiscal_year'] = $this->intField('fiscal_year', 2020, 2100);
            $options['as_of_date'] = (string) ($this->date('as_of_date', false) ?? '');
        }
        if ($type === ImportWorkflow::ANNUAL_BUDGET) $options['fiscal_year'] = $this->intField('fiscal_year', 2020, 2100);
        if ($type === ImportWorkflow::ENFORCEMENT_EXECUTIONS) $options['fiscal_year'] = $this->intField('fiscal_year', 2020, 2100);
        if ($type === ImportWorkflow::CAPITAL_BUDGET) {
            $options['fiscal_year'] = $this->intField('fiscal_year', 2020, 2100);
            $options['as_of_date'] = (string) $this->date('as_of_date');
            $options['default_funding_source'] = $this->requireField('default_funding_source', 40);
            if (!in_array($options['default_funding_source'], ['own_revenue','government_grant','health_grant','education_grant'], true)) {
                throw new HttpException(422, 'Изворот на финансирање не е валиден.');
            }
        }
        $file = $_FILES['file'];
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv','xlsx','xls'], true)) throw new HttpException(422, 'Дозволени се само CSV, XLSX и XLS.');
        if ($type === ImportWorkflow::ENFORCEMENT_EXECUTIONS && $extension !== 'xlsx') throw new HttpException(422, 'Табелата на извршувања мора да биде во формат XLSX.');
        if ($type === ImportWorkflow::FREEBALANCE_SUMMARY && !in_array($extension, ['xlsx','xls'], true)) throw new HttpException(422, 'FreeBalance извештајот мора да биде XLSX или XLS.');
        if ($type === ImportWorkflow::APPROVED_BUDGET && $extension !== 'xlsx') throw new HttpException(422, 'Одобрениот буџет мора да биде XLSX (се читаат сите листови).');
        if ((int) $file['size'] > $this->uploadLimitBytes()) throw new HttpException(422, 'Датотеката го надминува ефективниот лимит на серверот.');

        $id = Request::uuid();
        $directory = APP_ROOT.'/storage/private/imports/'.$id;
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Приватниот директориум не е создаден.');
        $path = $directory.'/source.'.$extension;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new \RuntimeException('Прикачувањето не успеа.');
        if (in_array($type, [ImportWorkflow::ENFORCEMENT_EXECUTIONS, ImportWorkflow::FREEBALANCE_SUMMARY, ImportWorkflow::APPROVED_BUDGET], true)) {
            $options['source_file_name'] = mb_substr(basename((string)$file['name']),0,255);
            $options['source_sha256'] = hash_file('sha256',$path) ?: '';
        }
        $storageKey = 'imports/'.$id.'/source.'.$extension;
        $this->db->execute(
            'INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,:type,:key,\'preview\',:user,SYSUTCDATETIME(),SYSUTCDATETIME())',
            ['id'=>$id, 'type'=>$type, 'key'=>$storageKey, 'user'=>$this->userId()]
        );
        try{$result=(new ImportWorkflow($this->db,$this->scope,$this->audit,$this->userId()))->preview($id,$type,$path,$options);}
        catch(LegacySpreadsheetException $exception){$this->db->execute("UPDATE import_batches SET status='invalid',errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id",['errors'=>json_encode([['row'=>1,'column'=>'file','code'=>'xls_conversion_failed']],JSON_UNESCAPED_UNICODE),'id'=>$id]);throw new HttpException(422,$exception->getMessage());}
        $this->redirect('/imports/'.(ImportWorkflow::TYPE_SECTIONS[$type] ?? '').'?batch_id='.rawurlencode($id), 'Batch '.$id.': '.$result['valid'].' валидни, '.$result['invalid'].' не се валидни.');
    }

    /** Budget execution (Finance / administrator): choose the project of an allocation. */
    public function executionAllocationProject(int $allocationId): never
    {
        if(!$this->scope->canLinkAcrossBudget($this->userId()))throw new HttpException(403,'Проектот во буџетското извршување го избира само Секторот за финансии или Администраторот.');
        $projectId=$this->chosenProject();
        $this->db->transaction(function()use($allocationId,$projectId){
            $before=$this->db->one('SELECT id,project_id,budget_line_id FROM budget_allocations WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$allocationId]);if(!$before)throw new HttpException(404,'Распределбата не е пронајдена.');
            if($projectId!==null&&$this->db->scalar('SELECT TOP 1 1 FROM budget_allocations WHERE budget_line_id=:line AND project_id=:project AND fiscal_year=(SELECT fiscal_year FROM budget_allocations WHERE id=:id) AND period=(SELECT period FROM budget_allocations WHERE id=:id2) AND task_id IS NULL AND id<>:self',['line'=>(int)$before['budget_line_id'],'project'=>$projectId,'id'=>$allocationId,'id2'=>$allocationId,'self'=>$allocationId]))throw new HttpException(422,'Проектот веќе има распределба во оваа ставка, година и период.');
            $this->db->execute('UPDATE budget_allocations SET project_id=:project,updated_at=SYSUTCDATETIME() WHERE id=:id',['project'=>$projectId,'id'=>$allocationId]);
            if($projectId!==null)$this->db->execute('UPDATE projects SET has_budget_impact=1,updated_at=SYSUTCDATETIME() WHERE id=:id AND has_budget_impact=0',['id'=>$projectId]);
            $this->audit->record($this->userId(),'allocation.project_changed','App\\Models\\BudgetAllocation',$allocationId,['project_id'=>$before['project_id']],['project_id'=>$projectId]);
        });
        $this->redirect($this->request->safeReferer('/execution'),'Проект на распределбата #'.$allocationId.' е ажуриран.');
    }

    /** Budget execution (Finance / administrator): link a budget line whose figures come from FreeBalance to a project. */
    public function executionLineProject(int $lineId): never
    {
        if(!$this->scope->canLinkAcrossBudget($this->userId()))throw new HttpException(403,'Проектот во буџетското извршување го избира само Секторот за финансии или Администраторот.');
        $projectId=$this->chosenProject();
        $this->db->transaction(function()use($lineId,$projectId){
            $before=$this->db->one('SELECT bl.id,bl.code,bl.linked_project_id,bc.base_year FROM budget_lines bl WITH (UPDLOCK,HOLDLOCK) JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bl.id=:id',['id'=>$lineId]);if(!$before)throw new HttpException(404,'Буџетската ставка не е пронајдена.');
            $this->db->execute('UPDATE budget_lines SET linked_project_id=:project,updated_at=SYSUTCDATETIME() WHERE id=:id',['project'=>$projectId,'id'=>$lineId]);
            (new BudgetExecution($this->db))->relink((int)$before['base_year']);
            $this->audit->record($this->userId(),'budget_line.project_linked','App\\Models\\BudgetLine',$lineId,['linked_project_id'=>$before['linked_project_id']],['linked_project_id'=>$projectId,'code'=>$before['code']]);
        });
        $this->redirect($this->request->safeReferer('/execution'),'Буџетската ставка е поврзана со проектот; бројките остануваат од FreeBalance.');
    }

    /** "#123 · CODE · Name" from the project picker → 123; empty → null (remove the link). */
    private function chosenProject(): ?int
    {
        $value=trim((string)$this->request->input('project',''));if($value==='')return null;
        if(!preg_match('/^#(\d+)\b/',$value,$m))throw new HttpException(422,'Изберете проект од листата (на пр. „#12 · P-102 · …“).');
        $project=$this->db->one("SELECT id FROM projects WHERE id=:id AND status<>'cancelled'",['id'=>(int)$m[1]]);if(!$project)throw new HttpException(422,'Проектот не е пронајден или е откажан.');
        return(int)$project['id'];
    }

    private const PROJECT_BUDGET_CATEGORIES = ['capital_investments'=>'Капитални инвестиции','goods_services'=>'Стоки и услуги','subsidies'=>'Субвенции','wages'=>'Плата','utilities'=>'Комунални трошоци'];

    private function projectBudgetCategory(): string
    {
        $category=trim((string)$this->request->input('budget_category','capital_investments'));
        if(!isset(self::PROJECT_BUDGET_CATEGORIES[$category]))throw new HttpException(422,'Буџетската категорија на проектот не е валидна.');
        return$category;
    }

    /**
     * A project uses approved budget lines of its own directorate and its own budget category. Lines of another directorate
     * or another category can be linked only by the Directorate of Finance and the system administrator.
     */
    private function assertProjectCategory(array $project, array $line): void
    {
        if($this->scope->canLinkAcrossBudget($this->userId()))return;
        if((int)$line['directorate_id']!==(int)$project['directorate_id'])throw new HttpException(403,'Буџетската ставка припаѓа на друг сектор. Проект со ставки од други сектори може да поврзе само Секторот за финансии или Администраторот.');
        $projectCategory=(string)($project['budget_category']??'capital_investments');$lineCategory=(string)($line['budget_category']??'');
        if($lineCategory!==''&&$lineCategory!==$projectCategory)throw new HttpException(422,'Буџетската ставка е од категоријата „'.(self::PROJECT_BUDGET_CATEGORIES[$lineCategory]??$lineCategory).'“, додека проектот е од категоријата „'.(self::PROJECT_BUDGET_CATEGORIES[$projectCategory]??$projectCategory).'“. Изберете ставка од истата категорија, променете ја категоријата на проектот или побарајте поврзување од Секторот за финансии или Администраторот.');
    }

    /** @return list<string> program codes typed in the directorate form */
    private function budgetCodes(): array
    {
        $value=trim((string)$this->request->input('budget_codes',''));
        if(mb_strlen($value)>200)throw new HttpException(422,'Листата на буџетски шифри е предолга.');
        try{return BudgetExecution::parseBudgetCodes($value);}catch(\InvalidArgumentException $e){throw new HttpException(422,$e->getMessage());}
    }

    /** Saves the FreeBalance program → directorate map and re-links every imported line. */
    public function freeBalancePrograms(): never
    {
        if (!$this->scope->canImportBudgetExecution($this->userId())) throw new HttpException(403, 'Само Финансии можат да ја менуваат врската на програмите.');
        $input = $this->request->input('directorate', []);
        if (!is_array($input)) throw new HttpException(422, 'Врската на програмите не е валидна.');
        $directorates = array_map('intval', array_column($this->db->all('SELECT id FROM directorates WHERE active=1'), 'id'));
        $changed = $this->db->transaction(function () use ($input, $directorates): int {
            $changed = 0;
            foreach ($this->db->all('SELECT program_code,program_name,directorate_id FROM freebalance_program_directorates WITH (UPDLOCK,HOLDLOCK)') as $row) {
                $code = (string) $row['program_code'];
                if (!array_key_exists($code, $input)) continue;
                $target = (int) $input[$code] ?: null;
                if ($target !== null && !in_array($target, $directorates, true)) throw new HttpException(422, 'Секторот избран за програмата '.$code.' не е активен.');
                $before = $row['directorate_id'] === null ? null : (int) $row['directorate_id'];
                if ($before === $target) continue;
                $this->db->execute('UPDATE freebalance_program_directorates SET directorate_id=:directorate,updated_by=:user,updated_at=SYSUTCDATETIME() WHERE program_code=:code', ['directorate'=>$target, 'user'=>$this->userId(), 'code'=>$code]);
                $this->audit->record($this->userId(), 'freebalance_program.mapped', 'FreeBalanceProgram', $code, ['directorate_id'=>$before], ['directorate_id'=>$target, 'program_name'=>$row['program_name']]);
                $changed++;
            }
            if ($changed > 0) { $execution = new BudgetExecution($this->db); $execution->relink(); $execution->syncDirectorateCodes(); }
            return $changed;
        });
        $this->redirect('/budget/freebalance#programs', $changed > 0 ? $changed.' програми се поврзани; FreeBalance ставките повторно се поврзани со секторите и проектите.' : 'Немаше промени.');
    }

    public function importConfirm(): never
    {
        $id = $this->requireField('batch_id', 36);
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) throw new HttpException(422, 'ID на пакетот не е валиден.');
        $batch = $this->db->one('SELECT * FROM import_batches WHERE id=:id', ['id'=>$id]);
        if (!$batch) throw new HttpException(404, 'Пакетот не е пронајден.');
        if ((string)$batch['type'] === ImportWorkflow::ENFORCEMENT_EXECUTIONS && !$this->scope->canImportExecutedCases($this->userId())) throw new HttpException(403, 'Само службениците од Финансии можат да го потврдат овој увоз.');
        if (in_array((string)$batch['type'], [ImportWorkflow::FREEBALANCE_SUMMARY, ImportWorkflow::APPROVED_BUDGET], true) && !$this->scope->canImportBudgetExecution($this->userId())) throw new HttpException(403, 'Само службениците од Финансии можат да го потврдат овој увоз.');
        if ((int) $batch['created_by'] !== $this->userId() && !array_intersect(['mayor_admin','system_admin'], $this->scope->roles($this->userId()))) {
            throw new HttpException(403, 'Не можете да го потврдите овој пакет.');
        }
        $result = (new ImportWorkflow($this->db, $this->scope, $this->audit, $this->userId()))->confirm($batch, ['retire_orphans'=>(string) $this->request->input('retire_orphans', '') === '1', 'skip_invalid'=>(string) $this->request->input('skip_invalid', '') === '1']);
        if((string)$batch['type']===ImportWorkflow::ENFORCEMENT_EXECUTIONS)$this->notifyEnforcementDirectorates($id);
        $skipped = isset($result['skipped']) && $result['skipped'] ? ' Прескокнати (без промена): '.implode(', ', array_column($result['skipped'], 'code')).'.' : '';
        $this->redirect('/imports/'.(ImportWorkflow::TYPE_SECTIONS[(string)$batch['type']] ?? '').'?batch_id='.rawurlencode($id), 'Увозот е атомски објавен: '.$result['posted'].' rreshta.'.$skipped);
    }

    /**
     * System administrator only: removes import history. Unposted batches (preview/invalid) are deleted with their rows and
     * uploaded files. Posted batches are deleted too when no imported data references them; otherwise only their staging rows
     * and file are removed and the batch header stays, because posted data and the audit trail point to it.
     */
    public function importsCleanup(): never
    {
        if (!$this->scope->isSystemAdmin($this->userId())) throw new HttpException(403, 'Само Администраторот на системот може да ја исчисти историјата на увозите.');
        $days = $this->intField('older_than_days', 0, 3650);
        $includePosted = (string) $this->request->input('include_posted', '') === '1';
        $confirm = trim((string) $this->request->input('confirm_text', ''));
        if ($confirm !== 'ИСЧИСТИ') throw new HttpException(422, 'Напишете ИСЧИСТИ за да го потврдите чистењето.');
        $result = $this->db->transaction(function () use ($days, $includePosted): array {
            $params = ['cutoff'=>$days];
            $batches = $this->db->all("SELECT ib.id,ib.status,ib.type,
                CASE WHEN EXISTS (SELECT 1 FROM budget_execution_lines x WHERE x.import_batch_id=ib.id) OR EXISTS (SELECT 1 FROM budget_execution_snapshots x WHERE x.import_batch_id=ib.id)
                       OR EXISTS (SELECT 1 FROM enforcement_executions x WHERE x.import_batch_id=ib.id) OR EXISTS (SELECT 1 FROM budget_line_code_changes x WHERE x.import_batch_id=ib.id) THEN 1 ELSE 0 END referenced
                FROM import_batches ib WITH (UPDLOCK,HOLDLOCK) WHERE ib.created_at < DATEADD(day,-:cutoff,SYSUTCDATETIME())", $params);
            $counts = ['deleted_unposted'=>0, 'deleted_posted'=>0, 'kept_referenced'=>0, 'rows_removed'=>0];
            foreach ($batches as $batch) {
                $posted = (string) $batch['status'] === 'posted';
                if ($posted && !$includePosted) continue;
                $counts['rows_removed'] += $this->db->execute('DELETE FROM import_rows WHERE import_batch_id=:id', ['id'=>$batch['id']]);
                $this->removeImportFiles((string) $batch['id']);
                if ($posted && (int) $batch['referenced'] === 1) { $counts['kept_referenced']++; continue; }
                $this->db->execute('DELETE FROM import_batches WHERE id=:id', ['id'=>$batch['id']]);
                $counts[$posted ? 'deleted_posted' : 'deleted_unposted']++;
            }
            $this->audit->record($this->userId(), 'imports.history_cleaned', 'App\\Models\\ImportBatch', null, [], $counts + ['older_than_days'=>$days, 'include_posted'=>$includePosted]);
            return $counts;
        });
        $this->redirect('/imports#cleanup', 'Историјата е исчистена: '.$result['deleted_unposted'].' необјавени пакети и '.$result['deleted_posted'].' објавени се избришани; '.$result['kept_referenced'].' објавени се зачувани бидејќи увезените податоци се поврзани со нив (отстранети се само привремените редови).');
    }

    /** System administrator only: deletes one unposted batch (preview or invalid). */
    public function importDelete(string $id): never
    {
        if (!$this->scope->isSystemAdmin($this->userId())) throw new HttpException(403, 'Само Администраторот на системот може да брише увози.');
        $this->db->transaction(function () use ($id): void {
            $batch = $this->db->one('SELECT id,status,type FROM import_batches WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$id]);
            if (!$batch) throw new HttpException(404, 'Пакетот не е пронајден.');
            if ((string) $batch['status'] === 'posted') throw new HttpException(422, 'Објавените пакети се чистат само преку „Исчисти ја историјата“.');
            $this->db->execute('DELETE FROM import_batches WHERE id=:id', ['id'=>$id]);
            $this->removeImportFiles($id);
            $this->audit->record($this->userId(), 'import.deleted', 'App\\Models\\ImportBatch', $id, ['status'=>$batch['status'], 'type'=>$batch['type']], []);
        });
        $this->redirect('/imports', 'Batch-i '.$id.' е избришан.');
    }

    private function removeImportFiles(string $id): void
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) return;
        $directory = APP_ROOT.'/storage/private/imports/'.$id;
        if (!is_dir($directory) || is_link($directory)) return;
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory.'/'.$item;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        @rmdir($directory);
    }

    public function executedCaseFinance(int $id): never
    {
        if(!$this->scope->canManageExecutedCaseFinance($this->userId()))throw new HttpException(403,'Само Финансии можат да ги менуваат финансиските податоци.');
        $budgetLineId=$this->intField('budget_line_id',1,PHP_INT_MAX);$debit=$this->nonNegativeAmount('debit_amount');$credit=$this->nonNegativeAmount('credit_amount');
        if((float)$debit<=0&&(float)$credit<=0)throw new HttpException(422,'Должењето или побарувањето мора да биде поголемо од нула.');
        $reason=$this->requireField('reason',1000);$token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9a-f]{16}$/i',$token))throw new HttpException(422,'Верзијата не е валидна.');
        $notification=$this->db->transaction(function()use($id,$budgetLineId,$debit,$credit,$reason,$token){
            $before=$this->db->one('SELECT ee.*,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),ee.row_version))) row_version_token FROM enforcement_executions ee WITH (UPDLOCK,HOLDLOCK) WHERE ee.id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Извршениот предмет не е пронајден.');
            if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Податоците се изменети. Освежете ја страницата.');
            if(!$this->db->scalar('SELECT TOP 1 1 FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bl.id=:id AND bv.is_official=1 AND bc.base_year=:year',['id'=>$budgetLineId,'year'=>$before['fiscal_year']]))throw new HttpException(422,'Буџетската ставка не е активна за годината на предметот.');
            $net=(float)$debit-(float)$credit;$parts=(float)$before['principal_amount']+(float)$before['fine_amount']+(float)$before['court_fee_amount']+(float)$before['interest_amount'];$status=abs($parts-$net)<=0.0001?'classified':($parts>0?'needs_reclassification':'unclassified');
            $this->db->execute('UPDATE enforcement_executions SET budget_line_id=:line,extracted_budget_code=(SELECT code FROM budget_lines WHERE id=:line2),debit_amount=:debit,credit_amount=:credit,classification_status=:status,financial_reviewed_by=:user,financial_reviewed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id',['line'=>$budgetLineId,'line2'=>$budgetLineId,'debit'=>$debit,'credit'=>$credit,'status'=>$status,'user'=>$this->userId(),'id'=>$id]);
            $auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'enforcement_execution.finance_updated','EnforcementExecution',$id,$auditBefore,['budget_line_id'=>$budgetLineId,'debit_amount'=>$debit,'credit_amount'=>$credit,'classification_status'=>$status,'reason'=>$reason]);
            $target=$this->db->one('SELECT directorate_id,code FROM budget_lines WHERE id=:id',['id'=>$budgetLineId]);$target['classification_status']=$status;return$target;
        });
        if($notification)$this->notifyDirectorateBudgetImpact((int)$notification['directorate_id'],'Извршен предмет #'.$id.' е усогласено со ставката '.(string)$notification['code'].'.');
        if(($notification['classification_status']??'')==='needs_reclassification')$this->notifyLegalOffice($id);
        $this->redirect('/executed-cases/'.$id,'Финансиските податоци се ажурирани.');
    }

    public function executedCaseLegal(int $id): never
    {
        if(!$this->scope->canManageExecutedCaseLegal($this->userId()))throw new HttpException(403,'Само Правната служба може да ја зачува правната распределба.');
        $principal=$this->nonNegativeAmount('principal_amount');$fine=$this->nonNegativeAmount('fine_amount');$court=$this->nonNegativeAmount('court_fee_amount');$interest=$this->nonNegativeAmount('interest_amount');
        $procurementRaw=trim((string)$this->request->input('procurement_project_id',''));$procurementId=$procurementRaw===''?null:$this->intField('procurement_project_id',1,PHP_INT_MAX);
        $token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9a-f]{16}$/i',$token))throw new HttpException(422,'Верзијата не е валидна.');
        $this->db->transaction(function()use($id,$principal,$fine,$court,$interest,$procurementId,$token){
            $before=$this->db->one('SELECT ee.*,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),ee.row_version))) row_version_token FROM enforcement_executions ee WITH (UPDLOCK,HOLDLOCK) WHERE ee.id=:id',['id'=>$id]);if(!$before)throw new HttpException(404,'Извршениот предмет не е пронајден.');
            if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Податоците се изменети. Освежете ја страницата.');
            if($procurementId!==null&&!$this->db->scalar('SELECT TOP 1 1 FROM procurement_projects WHERE id=:id',['id'=>$procurementId]))throw new HttpException(422,'Предметот на набавката не е пронајден.');
            $net=(float)$before['net_amount'];if($net<0)throw new HttpException(422,'Кредитите/поврат не можат да се класифицираат како плаќање без финансиско усогласување.');$sum=(float)$principal+(float)$fine+(float)$court+(float)$interest;if($sum>$net+0.0001)throw new HttpException(422,'Составните делови не можат да го надминат нето збирот.');
            $status=$sum<=0.0001?'unclassified':(abs($sum-$net)<=0.0001?'classified':'partial');
            $this->db->execute('UPDATE enforcement_executions SET principal_amount=:principal,fine_amount=:fine,court_fee_amount=:court,interest_amount=:interest,classification_status=:status,procurement_project_id=:procurement,legal_classified_by=:user,legal_classified_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id',['principal'=>$principal,'fine'=>$fine,'court'=>$court,'interest'=>$interest,'status'=>$status,'procurement'=>$procurementId,'user'=>$this->userId(),'id'=>$id]);
            $auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'enforcement_execution.legal_classified','EnforcementExecution',$id,$auditBefore,['principal_amount'=>$principal,'fine_amount'=>$fine,'court_fee_amount'=>$court,'interest_amount'=>$interest,'classification_status'=>$status,'procurement_project_id'=>$procurementId]);
        });
        $this->redirect('/executed-cases/'.$id,'Правната распределба е зачувана.');
    }

    public function procurementProjectLink(int $directorateId): never
    {
        if(!$this->scope->allowsDirectorate($this->userId(),$directorateId))throw new HttpException(403,'Надвор од вашиот опсег.');
        $procurementId=$this->intField('procurement_project_id',1,PHP_INT_MAX);$projectId=$this->intField('project_id',1,PHP_INT_MAX);
        $this->db->transaction(function()use($directorateId,$procurementId,$projectId){
            if(!$this->db->scalar('SELECT TOP 1 1 FROM procurement_projects WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$procurementId]))throw new HttpException(404,'Записот од јавната набавка не е пронајден.');
            if(!$this->db->scalar('SELECT TOP 1 1 FROM projects WHERE id=:project AND directorate_id=:directorate',['project'=>$projectId,'directorate'=>$directorateId]))throw new HttpException(422,'Проектот не припаѓа на овој сектор.');
            if(!$this->db->scalar('SELECT TOP 1 1 FROM procurement_project_links WHERE procurement_project_id=:procurement AND project_id=:project',['procurement'=>$procurementId,'project'=>$projectId]))$this->db->execute('INSERT INTO procurement_project_links(procurement_project_id,project_id,linked_by) VALUES(:procurement,:project,:user)',['procurement'=>$procurementId,'project'=>$projectId,'user'=>$this->userId()]);
            $this->audit->record($this->userId(),'procurement_project.linked','ProcurementProject',$procurementId,[],['project_id'=>$projectId,'directorate_id'=>$directorateId]);
        });
        $this->redirect('/directorates/'.$directorateId,'Проектот е поврзан со листата на јавни набавки.');
    }

    public function citizenServiceWeeklyReport(int $serviceId): never
    {
        $service = $this->db->one('SELECT id,directorate_id,code,name,active FROM citizen_services WHERE id=:id', ['id'=>$serviceId]);
        if (!$service) throw new HttpException(404, 'Услугата не е пронајдена.');
        if (!(bool) $service['active']) throw new HttpException(422, 'Услугата е неактивна и не може да се известува.');
        if (!$this->scope->canReportCitizenServices($this->userId(), (int) $service['directorate_id'])) throw new HttpException(403, 'Немате дозвола да известувате за оваа услуга.');
        if (in_array($serviceId, (new \EKabineti\Service\CitizenServiceCatalog($this->db))->computedWindowIds(), true)) throw new HttpException(409, 'Овој шалтер се пресметува од известувањето по услуги и не се пополнува рачно.');

        $week = $this->date('week_start');
        $weekDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $week);
        if (!$weekDate || $weekDate->format('N') !== '1') throw new HttpException(422, 'Неделата мора да почнува во понеделник.');
        $metrics = $this->db->all('SELECT id,label,metric_role FROM citizen_service_metrics WHERE service_id=:service AND active=1 ORDER BY sort_order,id', ['service'=>$serviceId]);
        if ($metrics === []) throw new HttpException(422, 'Услугата нема активни показатели.');
        // Counts are whole numbers; euro amounts (role amount, e.g. approved subsidies) keep two decimals.
        $values = []; $amounts = [];
        foreach ($metrics as $metric) {
            $id = (int) $metric['id'];
            if ($metric['metric_role'] === 'amount') {
                $raw = str_replace(',', '.', trim((string) $this->request->input('metric_'.$id, '0')));
                if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $raw)) throw new HttpException(422, 'Износот „'.$metric['label'].'“ не е валиден.');
                $values[$id] = 0; $amounts[$id] = number_format((float) $raw, 2, '.', '');
            } else {
                $values[$id] = $this->intField('metric_'.$id, 0, 1000000000);
            }
        }
        $averageRaw = trim((string) $this->request->input('average_resolution_hours', ''));
        if ($averageRaw !== '' && !preg_match('/^(?:\d{1,6})(?:\.\d{1,2})?$/', $averageRaw)) throw new HttpException(422, 'Просечното време на решавање не е валидно.');
        $average = $averageRaw === '' ? null : number_format((float)$averageRaw, 2, '.', '');
        $notes = trim((string) $this->request->input('notes', '')) ?: null;
        if ($notes !== null && mb_strlen($notes) > 4000) throw new HttpException(422, 'Белешките се предолги.');

        $this->db->transaction(function () use ($serviceId, $service, $week, $values, $amounts, $average, $notes): void {
            $existing = $this->db->one('SELECT id,directorate_id,average_resolution_hours,notes FROM citizen_service_weekly_reports WITH (UPDLOCK,HOLDLOCK) WHERE service_id=:lock_service AND week_start=:lock_week', ['lock_service'=>$serviceId,'lock_week'=>$week]);
            if ($existing) {
                $reportId = (int) $existing['id'];
                $beforeValues = $this->db->all('SELECT metric_id,metric_value,amount_value FROM citizen_service_weekly_values WHERE report_id=:report ORDER BY metric_id', ['report'=>$reportId]);
                $this->db->execute('UPDATE citizen_service_weekly_reports SET average_resolution_hours=:average,notes=:notes,submitted_by=:user,submitted_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id', ['average'=>$average,'notes'=>$notes,'user'=>$this->userId(),'id'=>$reportId]);
            } else {
                $reportId = $this->db->insertId('INSERT INTO citizen_service_weekly_reports(service_id,directorate_id,week_start,average_resolution_hours,notes,submitted_by,submitted_at,updated_at) VALUES(:service,:directorate,:week,:average,:notes,:user,SYSUTCDATETIME(),SYSUTCDATETIME())', ['service'=>$serviceId,'directorate'=>(int)$service['directorate_id'],'week'=>$week,'average'=>$average,'notes'=>$notes,'user'=>$this->userId()]);
                $beforeValues = [];
            }
            $this->db->execute('DELETE FROM citizen_service_weekly_values WHERE report_id=:report', ['report'=>$reportId]);
            foreach ($values as $metricId=>$value) $this->db->execute('INSERT INTO citizen_service_weekly_values(report_id,metric_id,metric_value,amount_value,created_at,updated_at) VALUES(:report,:metric,:value,:amount,SYSUTCDATETIME(),SYSUTCDATETIME())', ['report'=>$reportId,'metric'=>$metricId,'value'=>$value,'amount'=>$amounts[$metricId] ?? null]);
            $this->audit->record($this->userId(), $existing ? 'citizen_service_report.updated' : 'citizen_service_report.created', 'CitizenServiceWeeklyReport', $reportId, $existing ? ['report'=>$existing,'values'=>$beforeValues] : [], ['service_id'=>$serviceId,'directorate_id'=>(int)$service['directorate_id'],'week_start'=>$week,'average_resolution_hours'=>$average,'notes'=>$notes,'values'=>$values,'amounts'=>$amounts]);
        });
        $this->redirect('/citizen-services?week_start='.rawurlencode((string)$week), 'Неделниот извештај е зачуван.');
    }

    public function citizenServiceStatus(int $serviceId): never
    {
        if (!$this->scope->canManageCitizenServices($this->userId())) throw new HttpException(403, 'Немате дозвола за администрирање на услугите.');
        $service = $this->db->one('SELECT id,code,name,directorate_id,active FROM citizen_services WHERE id=:id', ['id'=>$serviceId]);
        if (!$service) throw new HttpException(404, 'Услугата не е пронајдена.');
        $active = $this->intField('active', 0, 1);
        $reason = $this->requireField('reason', 1000);
        $this->db->execute('UPDATE citizen_services SET active=:active,updated_at=SYSUTCDATETIME() WHERE id=:id', ['active'=>$active,'id'=>$serviceId]);
        $this->audit->record($this->userId(), $active ? 'citizen_service.activated' : 'citizen_service.deactivated', 'CitizenService', $serviceId, ['active'=>(bool)$service['active']], ['active'=>(bool)$active], $reason);
        $this->redirect('/citizen-services', $active ? 'Услугата е активирана.' : 'Услугата е деактивирана.');
    }

    public function citizenServiceTransfer(int $serviceId): never
    {
        if (!$this->scope->canManageCitizenServices($this->userId())) throw new HttpException(403, 'Немате дозвола за администрирање на услугите.');
        $targetId = $this->intField('to_directorate_id', 1, PHP_INT_MAX);
        $reason = $this->requireField('reason', 1000);
        $target = $this->db->one('SELECT id,code,name FROM directorates WHERE id=:id AND active=1', ['id'=>$targetId]);
        if (!$target) throw new HttpException(422, 'Секторот што прима не е активен или не постои.');
        $this->db->transaction(function () use ($serviceId, $targetId, $target, $reason): void {
            $service = $this->db->one('SELECT id,code,name,directorate_id FROM citizen_services WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$serviceId]);
            if (!$service) throw new HttpException(404, 'Услугата не е пронајдена.');
            $fromId = (int) $service['directorate_id'];
            if ($fromId === $targetId) throw new HttpException(422, 'Услугата веќе припаѓа на овој сектор.');
            $this->db->execute('INSERT INTO citizen_service_transfers(service_id,from_directorate_id,to_directorate_id,reason,transferred_by,transferred_at) VALUES(:service,:from_directorate,:to_directorate,:reason,:user,SYSUTCDATETIME())', ['service'=>$serviceId,'from_directorate'=>$fromId,'to_directorate'=>$targetId,'reason'=>$reason,'user'=>$this->userId()]);
            $this->db->execute('UPDATE citizen_services SET directorate_id=:directorate,updated_at=SYSUTCDATETIME() WHERE id=:id', ['directorate'=>$targetId,'id'=>$serviceId]);
            $this->audit->record($this->userId(), 'citizen_service.transferred', 'CitizenService', $serviceId, ['directorate_id'=>$fromId], ['directorate_id'=>$targetId,'directorate_code'=>$target['code']], $reason);
        });
        $this->redirect('/citizen-services', 'Услугата е префрлена во новиот сектор; историските извештаи го чуваат секторот од пријавената недела.');
    }

    public function projectShare(int $projectId): never
    {
        $project=$this->find('projects',$projectId);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true,true);$user=$this->intField('user_id',1,PHP_INT_MAX);$level=$this->requireField('access_level',20);if(!in_array($level,['viewer','editor','manager'],true))throw new HttpException(422,'Нивото не е валидно.');if(!$this->db->scalar("SELECT TOP 1 1 FROM users WHERE id=:id AND status='active'",['id'=>$user]))throw new HttpException(422,'Корисникот не е активен.');$this->db->execute('MERGE project_user_access AS target USING (SELECT :project project_id,:user user_id) source ON target.project_id=source.project_id AND target.user_id=source.user_id WHEN MATCHED THEN UPDATE SET access_level=:level,granted_by=:granted,created_at=SYSUTCDATETIME() WHEN NOT MATCHED THEN INSERT(project_id,user_id,access_level,granted_by,created_at) VALUES(:project2,:user2,:level2,:granted2,SYSUTCDATETIME());',['project'=>$projectId,'user'=>$user,'level'=>$level,'granted'=>$this->userId(),'project2'=>$projectId,'user2'=>$user,'level2'=>$level,'granted2'=>$this->userId()]);$this->audit->record($this->userId(),'project.shared','Project',$projectId,[],['user_id'=>$user,'access_level'=>$level]);$this->notifications->notify($user,'project_shared','Споделено со вас во проект · '.$project['name'],['project_id'=>$projectId,'body'=>'Додадени сте во проектот '.$project['name'].' со ниво '.$level.'.']);$this->redirect('/projects/'.$projectId,'Проектот е споделен со корисникот.');
    }

    public function taskShare(int $projectId,int $taskId): never
    {
        $project=$this->find('projects',$projectId);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->project((int)$project['id'],true,true);$task=$this->db->one('SELECT id,title FROM project_tasks WHERE id=:id AND project_id=:project',['id'=>$taskId,'project'=>$projectId]);if(!$task)throw new HttpException(404,'Задачата не е пронајдена.');$user=$this->intField('user_id',1,PHP_INT_MAX);$level=$this->requireField('access_level',20);(new EvidenceAccess($this->db,$this->scope,$this->userId()))->activeUser($user);if(!in_array($level,['viewer','editor'],true))throw new HttpException(422,'Нивото не е валидно.');$this->db->execute('MERGE task_user_access AS target USING (SELECT :task task_id,:user user_id) source ON target.task_id=source.task_id AND target.user_id=source.user_id WHEN MATCHED THEN UPDATE SET access_level=:level,granted_by=:granted,created_at=SYSUTCDATETIME() WHEN NOT MATCHED THEN INSERT(task_id,user_id,access_level,granted_by,created_at) VALUES(:task2,:user2,:level2,:granted2,SYSUTCDATETIME());',['task'=>$taskId,'user'=>$user,'level'=>$level,'granted'=>$this->userId(),'task2'=>$taskId,'user2'=>$user,'level2'=>$level,'granted2'=>$this->userId()]);$this->audit->record($this->userId(),'task.shared','ProjectTask',$taskId,[],['project_id'=>$projectId,'user_id'=>$user,'access_level'=>$level]);$this->notifications->notify($user,'task_shared','Споделено со вас во задача · '.$task['title'],['project_id'=>$projectId,'task_id'=>$taskId,'body'=>'Додадени сте во задачата '.$task['title'].'.']);$this->redirect('/projects/'.$projectId,'Задачата е споделена со корисникот.');
    }

    public function legalCaseCreate(): never
    {
        $data=$this->legalCaseData();$duplicate=$data['court_reference']!==null?(bool)$this->db->scalar('SELECT TOP 1 1 FROM administrative_cases WHERE is_legal=1 AND court_reference=:reference',['reference'=>$data['court_reference']]):false;
        $id=$this->db->transaction(function()use($data){$sequence=(int)$this->db->scalar('SELECT COALESCE(MAX(internal_sequence),0)+1 FROM administrative_cases WITH (TABLOCKX,HOLDLOCK)');$number='ZL-'.date('Y').'-'.str_pad((string)$sequence,6,'0',STR_PAD_LEFT);$legalDirectorate=(int)$this->db->scalar("SELECT id FROM directorates WHERE code='PRAV'");$params=$data+['sequence'=>$sequence,'number'=>$number,'responsible'=>$this->userId(),'user'=>$this->userId()];
            $id=$this->db->insertId("INSERT INTO administrative_cases(case_uuid,internal_sequence,is_legal,case_number,case_type,subject,description,originating_directorate_id,responsible_user_id,status,priority,opened_at,created_by,created_at,updated_at,external_reference,protocol_number,source_name,court_reference,authority_name,procedural_phase,outcome,assigned_at,project_id,procurement_project_id,budget_line_id,financial_direction,claimed_amount,recognized_amount,realized_amount,currency,confidentiality) VALUES(NEWSEQUENTIALID(),:sequence,1,:number,:case_type,:subject,:description,:directorate_id,:responsible,:status,:priority,CONVERT(date,SYSUTCDATETIME()),:user,SYSUTCDATETIME(),SYSUTCDATETIME(),:external_reference,:protocol_number,:source_name,:court_reference,:authority_name,:procedural_phase,:outcome,SYSUTCDATETIME(),:project_id,:procurement_project_id,:budget_line_id,:financial_direction,:claimed_amount,:recognized_amount,:realized_amount,'MKD',:confidentiality)",$params);
            $this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,responsible_user_id,status,assigned_by,assigned_at) VALUES(:case,:legal,'OWNER',:responsible,'open',:user,SYSUTCDATETIME())",['case'=>$id,'legal'=>$legalDirectorate,'responsible'=>$this->userId(),'user'=>$this->userId()]);if($legalDirectorate!==(int)$data['directorate_id'])$this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,status,assigned_by,assigned_at) VALUES(:case,:directorate,'RELATED','open',:user,SYSUTCDATETIME())",['case'=>$id,'directorate'=>$data['directorate_id'],'user'=>$this->userId()]);
            $this->db->execute('INSERT INTO legal_case_assignments(case_id,responsible_user_id,valid_from,assigned_by,reason) VALUES(:case,:responsible,SYSUTCDATETIME(),:user,:reason)',['case'=>$id,'responsible'=>$this->userId(),'user'=>$this->userId(),'reason'=>'Почетно создавање на досието']);$this->audit->record($this->userId(),'legal_case.created','AdministrativeCase',$id,[],$data+['case_number'=>$number,'internal_sequence'=>$sequence]);return$id;});
        if($data['budget_line_id']!==null)$this->notifyLegalCaseBudgetOwner($id,(int)$data['budget_line_id'],(float)($data['recognized_amount']??$data['claimed_amount']??0),(string)($data['financial_direction']??''));$this->redirect('/legal-office/cases/'.$id,$duplicate?'Досието е создадено. Внимание: судскиот број е користен и во друго досие.':'Правното досие е создадено.');
    }

    public function legalCaseUpdate(int$id): never
    {
        $data=$this->legalCaseData();$token=$this->requireField('row_version',18);$reason=$this->requireField('reason',1000);if(!preg_match('/^0x[0-9a-f]{16}$/i',$token))throw new HttpException(422,'Верзијата не е валидна.');
        $before=$this->db->transaction(function()use($id,$data,$token,$reason){$before=$this->db->one("SELECT *,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM administrative_cases WITH(UPDLOCK,HOLDLOCK) WHERE id=:id AND is_legal=1",['id'=>$id]);if(!$before)throw new HttpException(404,'Правниот предмет не е пронајден.');(new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Досието е изменето од друг корисник. Освежете ја страницата.');if(in_array($data['status'],['closed','archived'],true)){if($this->db->scalar("SELECT TOP 1 1 FROM case_tasks WHERE case_id=:id AND status<>'completed'",['id'=>$id]))throw new HttpException(422,'Завршете ги отворените рокови и задачи пред затворањето.');if($this->db->scalar("SELECT TOP 1 1 FROM legal_case_procedures WHERE case_id=:id AND status IN('active','suspended')",['id'=>$id]))throw new HttpException(422,'Завршете ги активните постапки пред затворањето.');}
            $params=$data+['id'=>$id,'status_close'=>$data['status']];$this->db->execute("UPDATE administrative_cases SET case_type=:case_type,subject=:subject,description=:description,originating_directorate_id=:directorate_id,status=:status,priority=:priority,external_reference=:external_reference,protocol_number=:protocol_number,source_name=:source_name,court_reference=:court_reference,authority_name=:authority_name,procedural_phase=:procedural_phase,outcome=:outcome,project_id=:project_id,procurement_project_id=:procurement_project_id,budget_line_id=:budget_line_id,financial_direction=:financial_direction,claimed_amount=:claimed_amount,recognized_amount=:recognized_amount,realized_amount=:realized_amount,confidentiality=:confidentiality,closed_at=CASE WHEN :status_close IN('closed','archived') THEN COALESCE(closed_at,CONVERT(date,SYSUTCDATETIME())) ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",$params);$auditBefore=$before;unset($auditBefore['row_version'],$auditBefore['row_version_token']);$this->audit->record($this->userId(),'legal_case.updated','AdministrativeCase',$id,$auditBefore,$data,$reason);return$before;});
        if($data['budget_line_id']!==null&&((int)($before['budget_line_id']??0)!==(int)$data['budget_line_id']||(float)($before['recognized_amount']??0)!==(float)($data['recognized_amount']??0)))$this->notifyLegalCaseBudgetOwner($id,(int)$data['budget_line_id'],(float)($data['recognized_amount']??$data['claimed_amount']??0),(string)($data['financial_direction']??''));$this->redirect('/legal-office/cases/'.$id,'Правното досие е ажурирано.');
    }

    public function legalCaseParty(int$id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);$name=$this->requireField('party_name',300);$role=$this->requireField('party_role',40);if(!in_array($role,['claimant','respondent','intervenor','authority','other'],true))throw new HttpException(422,'Улогата на странката не е валидна.');$partyId=$this->db->insertId('INSERT INTO legal_case_parties(case_id,party_name,party_role,external_identifier,contact_details,representative,created_by) VALUES(:case,:name,:role,:external,:contact,:representative,:user)',['case'=>$id,'name'=>$name,'role'=>$role,'external'=>trim((string)$this->request->input('external_identifier',''))?:null,'contact'=>trim((string)$this->request->input('contact_details',''))?:null,'representative'=>trim((string)$this->request->input('representative',''))?:null,'user'=>$this->userId()]);$this->audit->record($this->userId(),'legal_case.party_added','LegalCaseParty',$partyId,[],['case_id'=>$id,'party_name'=>$name,'party_role'=>$role]);$this->redirect('/legal-office/cases/'.$id,'Странката е додадена.');
    }

    public function legalCaseProcedure(int$id): never
    {
        $case=(new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);$type=$this->requireField('procedure_type',30);$phase=$this->requireField('phase',30);$status=$this->requireField('status',30);if(!in_array($type,['judicial','appeal','enforcement','legal_opinion','other'],true)||!in_array($phase,['first_instance','appeal','revision','retrial','enforcement'],true)||!in_array($status,['active','suspended','completed'],true))throw new HttpException(422,'Видот, фазата или статусот на постапката не е валиден.');$started=$this->date('started_at',false);$ended=$this->date('ended_at',false);if($started&&$ended&&$ended<$started)throw new HttpException(422,'Завршувањето не може да биде пред почетокот.');$direction=trim((string)$this->request->input('financial_direction',''))?:null;if($direction!==null&&!in_array($direction,['receivable','payable'],true))throw new HttpException(422,'Финансиската насока не е валидна.');$principal=$this->nonNegativeAmount('principal_amount');$interest=$this->nonNegativeAmount('interest_amount');$cost=$this->nonNegativeAmount('cost_amount');$realized=$this->nonNegativeAmount('realized_amount');if((float)$realized>(float)$principal+(float)$interest+(float)$cost+0.0001)throw new HttpException(422,'Реализацијата не може да ја надмине обврската од постапката.');$procurement=$this->request->integer('procurement_project_id')?:null;$budget=$this->request->integer('budget_line_id')?:null;$this->assertLegalLinks((int)$case['originating_directorate_id'],$procurement,$budget,null);
        $obligation=trim((string)$this->request->input('obligation_reference',''))?:null;if($obligation!==null&&mb_strlen($obligation)>150)throw new HttpException(422,'Референцата на обврската е предолга.');$procedureId=$this->db->insertId('INSERT INTO legal_case_procedures(case_id,procedure_type,phase,status,external_reference,authority_name,started_at,ended_at,outcome,procurement_project_id,budget_line_id,financial_direction,obligation_reference,principal_amount,interest_amount,cost_amount,realized_amount,created_by) VALUES(:case,:type,:phase,:status,:external,:authority,:started,:ended,:outcome,:procurement,:budget,:direction,:obligation,:principal,:interest,:cost,:realized,:user)',['case'=>$id,'type'=>$type,'phase'=>$phase,'status'=>$status,'external'=>trim((string)$this->request->input('external_reference',''))?:null,'authority'=>trim((string)$this->request->input('authority_name',''))?:null,'started'=>$started,'ended'=>$ended,'outcome'=>trim((string)$this->request->input('outcome',''))?:null,'procurement'=>$procurement,'budget'=>$budget,'direction'=>$direction,'obligation'=>$obligation,'principal'=>$principal,'interest'=>$interest,'cost'=>$cost,'realized'=>$realized,'user'=>$this->userId()]);$this->audit->record($this->userId(),'legal_case.procedure_added','LegalCaseProcedure',$procedureId,[],['case_id'=>$id,'phase'=>$phase,'status'=>$status]);if($budget!==null)$this->notifyLegalCaseBudgetOwner($id,$budget,(float)$principal+(float)$interest+(float)$cost,$direction??'');$this->redirect('/legal-office/cases/'.$id,'Постапката е додадена.');
    }

    public function legalCaseHearing(int$id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);$procedure=$this->request->integer('procedure_id')?:null;if($procedure&&!$this->db->scalar('SELECT TOP 1 1 FROM legal_case_procedures WHERE id=:procedure AND case_id=:case',['procedure'=>$procedure,'case'=>$id]))throw new HttpException(422,'Постапката не припаѓа на ова досие.');$raw=$this->requireField('scheduled_at',16);if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$raw))throw new HttpException(422,'Датумот и часот на рочиштето не се валидни.');$responsible=$this->request->integer('responsible_user_id')?:null;if($responsible)$this->assertLegalStaff($responsible);$status=$this->requireField('status',30);if(!in_array($status,['scheduled','held','postponed','cancelled'],true))throw new HttpException(422,'Статусот на рочиштето не е валиден.');$hearingId=$this->db->insertId('INSERT INTO legal_case_hearings(case_id,procedure_id,scheduled_at,authority_name,venue_mode,responsible_user_id,status,notes,created_by) VALUES(:case,:procedure,:scheduled,:authority,:venue,:responsible,:status,:notes,:user)',['case'=>$id,'procedure'=>$procedure,'scheduled'=>str_replace('T',' ',$raw),'authority'=>$this->requireField('authority_name',250),'venue'=>trim((string)$this->request->input('venue_mode',''))?:null,'responsible'=>$responsible,'status'=>$status,'notes'=>trim((string)$this->request->input('notes',''))?:null,'user'=>$this->userId()]);if($responsible)$this->notifications->notify($responsible,'legal_hearing_scheduled','Ново правно рочиште',['body'=>'Рочиште за предметот #'.$id.' е закажано на '.str_replace('T',' ',$raw).'.','url'=>'/legal-office/cases/'.$id]);$this->audit->record($this->userId(),'legal_case.hearing_added','LegalCaseHearing',$hearingId,[],['case_id'=>$id,'scheduled_at'=>$raw]);$this->redirect('/legal-office/cases/'.$id,'Рочиштето е додадено.');
    }

    public function legalCaseDeadline(int$id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);$title=$this->requireField('title',250);$assigned=$this->intField('assigned_to',1,PHP_INT_MAX);$this->assertLegalStaff($assigned);$starts=$this->date('starts_at',false);$due=$this->date('due_at');if($starts&&$due<$starts)throw new HttpException(422,'Рокот не може да биде пред почетниот датум.');$basis=$this->requireField('legal_basis',1000);$taskId=$this->db->insertId("INSERT INTO case_tasks(case_id,title,description,assigned_to,due_at,status,created_by,created_at,updated_at,task_type,starts_at,legal_basis) VALUES(:case,:title,:basis,:assigned,:due,'open',:user,SYSUTCDATETIME(),SYSUTCDATETIME(),'deadline',:starts,:basis2)",['case'=>$id,'title'=>$title,'basis'=>$basis,'assigned'=>$assigned,'due'=>$due,'user'=>$this->userId(),'starts'=>$starts,'basis2'=>$basis]);$this->notifications->notify($assigned,'legal_deadline_assigned','Законски рок · '.$title,['body'=>'Потврден рок: '.$due.'.','url'=>'/legal-office/cases/'.$id]);$this->audit->record($this->userId(),'legal_case.deadline_added','CaseTask',$taskId,[],['case_id'=>$id,'due_at'=>$due]);$this->redirect('/legal-office/cases/'.$id,'Законскиот рок е додаден.');
    }

    public function legalCaseAssign(int$id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true,true);$responsible=$this->intField('responsible_user_id',1,PHP_INT_MAX);$this->assertLegalStaff($responsible);$deputy=$this->request->integer('deputy_user_id')?:null;if($deputy){$this->assertLegalStaff($deputy);if($deputy===$responsible)throw new HttpException(422,'Заменикот мора да биде друг корисник.');}$reason=$this->requireField('reason',1000);$this->db->transaction(function()use($id,$responsible,$deputy,$reason){$before=$this->db->one('SELECT responsible_user_id,deputy_user_id FROM administrative_cases WITH(UPDLOCK,HOLDLOCK) WHERE id=:id AND is_legal=1',['id'=>$id]);if(!$before)throw new HttpException(404,'Предметот не е пронајден.');$this->db->execute('UPDATE legal_case_assignments SET valid_to=SYSUTCDATETIME() WHERE case_id=:case AND valid_to IS NULL',['case'=>$id]);$this->db->execute('INSERT INTO legal_case_assignments(case_id,responsible_user_id,deputy_user_id,valid_from,assigned_by,reason) VALUES(:case,:responsible,:deputy,SYSUTCDATETIME(),:user,:reason)',['case'=>$id,'responsible'=>$responsible,'deputy'=>$deputy,'user'=>$this->userId(),'reason'=>$reason]);$this->db->execute('UPDATE administrative_cases SET responsible_user_id=:responsible,deputy_user_id=:deputy,assigned_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id',['responsible'=>$responsible,'deputy'=>$deputy,'id'=>$id]);$this->audit->record($this->userId(),'legal_case.assigned','AdministrativeCase',$id,$before,['responsible_user_id'=>$responsible,'deputy_user_id'=>$deputy],$reason);});foreach(array_filter([$responsible,$deputy])as$user)$this->notifications->notify((int)$user,'legal_case_assigned','Определување на правен предмет #'.$id,['body'=>'Определени сте на правното досие #'.$id.'.','url'=>'/legal-office/cases/'.$id]);$this->redirect('/legal-office/cases/'.$id,'Одговорното лице е определено.');
    }

    public function legalCaseExecutionLink(int$id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);$execution=$this->intField('execution_id',1,PHP_INT_MAX);$procedure=$this->request->integer('procedure_id')?:null;if($procedure&&!$this->db->scalar("SELECT TOP 1 1 FROM legal_case_procedures WHERE id=:procedure AND case_id=:case AND procedure_type='enforcement'",['procedure'=>$procedure,'case'=>$id]))throw new HttpException(422,'Изберете извршна постапка од ова досие.');$this->db->transaction(function()use($id,$execution,$procedure){$before=$this->db->one('SELECT administrative_case_id,legal_procedure_id FROM enforcement_executions WITH(UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$execution]);if(!$before)throw new HttpException(404,'Извршувањето не е пронајдено.');if($before['administrative_case_id']!==null&&(int)$before['administrative_case_id']!==$id)throw new HttpException(409,'Извршувањето е поврзано со друго досие.');$this->db->execute('UPDATE enforcement_executions SET administrative_case_id=:case,legal_procedure_id=:procedure,updated_at=SYSUTCDATETIME() WHERE id=:id',['case'=>$id,'procedure'=>$procedure,'id'=>$execution]);$this->audit->record($this->userId(),'legal_case.execution_linked','EnforcementExecution',$execution,$before,['administrative_case_id'=>$id,'legal_procedure_id'=>$procedure]);});$this->redirect('/legal-office/cases/'.$id,'Извршувањето е поврзано без да се дуплира износот.');
    }

    public function legalCaseDeadlineStatus(int $caseId,int $taskId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true);$status=$this->requireField('status',30);if(!in_array($status,['open','in_progress','blocked','completed'],true))throw new HttpException(422,'Статусот на рокот не е валиден.');$note=trim((string)$this->request->input('completion_note',''))?:null;if($status==='completed'&&$note===null)throw new HttpException(422,'Завршувањето на рокот бара документирачка белешка.');
        $this->db->transaction(function()use($caseId,$taskId,$status,$note){$before=$this->db->one("SELECT * FROM case_tasks WITH(UPDLOCK,HOLDLOCK) WHERE id=:id AND case_id=:case AND task_type='deadline'",['id'=>$taskId,'case'=>$caseId]);if(!$before)throw new HttpException(404,'Рокот не е пронајден.');$this->db->execute("UPDATE case_tasks SET status=:status,description=CASE WHEN :note IS NULL THEN description ELSE :note2 END,completed_at=CASE WHEN :status2='completed' THEN SYSUTCDATETIME() ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",['status'=>$status,'note'=>$note,'note2'=>$note,'status2'=>$status,'id'=>$taskId]);$this->db->execute('INSERT INTO case_actions(case_id,action_type,description,previous_status,new_status,action_date,performed_by) VALUES(:case,:type,:description,:previous,:new,SYSUTCDATETIME(),:user)',['case'=>$caseId,'type'=>'LEGAL_DEADLINE_STATUS','description'=>$note??('Рок #'.$taskId.' е поставен на '.$status),'previous'=>$before['status'],'new'=>$status,'user'=>$this->userId()]);$this->audit->record($this->userId(),'legal_case.deadline_status','CaseTask',$taskId,['status'=>$before['status']],['status'=>$status,'completion_note'=>$note]);});$this->redirect('/legal-office/cases/'.$caseId,'Статусот на рокот е зачуван.');
    }

    public function legalCaseProcedureStatus(int $caseId,int $procedureId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true);$status=$this->requireField('status',30);if(!in_array($status,['active','suspended','completed'],true))throw new HttpException(422,'Статусот на постапката не е валиден.');$outcome=trim((string)$this->request->input('outcome',''))?:null;if($status==='completed'&&$outcome===null)throw new HttpException(422,'Завршената постапка бара исход.');
        $this->db->transaction(function()use($caseId,$procedureId,$status,$outcome){$before=$this->db->one('SELECT id,status,outcome FROM legal_case_procedures WITH(UPDLOCK,HOLDLOCK) WHERE id=:id AND case_id=:case',['id'=>$procedureId,'case'=>$caseId]);if(!$before)throw new HttpException(404,'Постапката не е пронајдена.');$this->db->execute("UPDATE legal_case_procedures SET status=:status,outcome=COALESCE(:outcome,outcome),ended_at=CASE WHEN :status2='completed' THEN COALESCE(ended_at,CONVERT(date,SYSUTCDATETIME())) ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",['status'=>$status,'outcome'=>$outcome,'status2'=>$status,'id'=>$procedureId]);$this->audit->record($this->userId(),'legal_case.procedure_status','LegalCaseProcedure',$procedureId,$before,['status'=>$status,'outcome'=>$outcome]);});$this->redirect('/legal-office/cases/'.$caseId,'Статусот на постапката е зачуван.');
    }

    public function legalCaseHearingStatus(int $caseId,int $hearingId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true);$status=$this->requireField('status',30);if(!in_array($status,['scheduled','held','postponed','cancelled'],true))throw new HttpException(422,'Статусот на рочиштето не е валиден.');$notes=trim((string)$this->request->input('notes',''))?:null;if(in_array($status,['held','postponed','cancelled'],true)&&$notes===null)throw new HttpException(422,'Промената на статусот бара белешка.');$before=$this->db->one('SELECT id,status,notes FROM legal_case_hearings WHERE id=:id AND case_id=:case',['id'=>$hearingId,'case'=>$caseId]);if(!$before)throw new HttpException(404,'Рочиштето не е пронајдено.');$this->db->execute('UPDATE legal_case_hearings SET status=:status,notes=COALESCE(:notes,notes) WHERE id=:id',['status'=>$status,'notes'=>$notes,'id'=>$hearingId]);$this->audit->record($this->userId(),'legal_case.hearing_status','LegalCaseHearing',$hearingId,$before,['status'=>$status,'notes'=>$notes]);$this->redirect('/legal-office/cases/'.$caseId,'Статусот на рочиштето е зачуван.');
    }

    public function legalOfficeUser(): never
    {
        [$name,$username,$email,$hash]=$this->userIdentity();$this->assertUniqueIdentity($username,$email);$roleCode=$this->requireField('role_code',50);if(!in_array($roleCode,['senior_legal_officer','legal_officer','legal_assistant'],true))throw new HttpException(422,'Правниот профил не е валиден.');$from=$this->date('valid_from',false);$to=$this->date('valid_to',false);if($from&&$to&&$to<$from)throw new HttpException(422,'Периодот на пристап не е валиден.');$role=$this->db->one('SELECT id,name FROM roles WHERE code=:code',['code'=>$roleCode]);$directorate=(int)$this->db->scalar("SELECT id FROM directorates WHERE code='PRAV' AND active=1");if(!$role||$directorate<=0)throw new HttpException(500,'Конфигурацијата на Правната служба недостасува.');$this->db->transaction(function()use($name,$username,$email,$hash,$role,$roleCode,$directorate,$from,$to){$id=$this->db->insertId("INSERT INTO users(name,username,email,email_verified_at,password,locale,status,created_at,updated_at) VALUES(:name,:username,:email,SYSUTCDATETIME(),:password,'mk','active',SYSUTCDATETIME(),SYSUTCDATETIME())",['name'=>$name,'username'=>$username,'email'=>$email,'password'=>$hash]);$this->db->execute('INSERT INTO user_role_scopes(user_id,role_id,directorate_id,valid_from,valid_to,granted_by,reason,created_at,updated_at) VALUES(:user,:role,:directorate,:from,:to,:granted,:reason,SYSUTCDATETIME(),SYSUTCDATETIME())',['user'=>$id,'role'=>$role['id'],'directorate'=>$directorate,'from'=>$from,'to'=>$to,'granted'=>$this->userId(),'reason'=>'Пристап доделен од Раководителот на Правната служба']);$this->audit->record($this->userId(),'legal_office.user_created','User',$id,[],['username'=>$username,'email'=>$email,'role'=>$roleCode,'valid_from'=>$from,'valid_to'=>$to]);});$this->redirect('/legal-office/users','Правниот службеник е создаден со ограничен пристап до ПРАВНА.');
    }

    public function legalOfficeUserAccess(int$scopeId): never
    {
        $from=$this->date('valid_from',false);$to=$this->date('valid_to',false);if($from&&$to&&$to<$from)throw new HttpException(422,'Периодот на пристап не е валиден.');$status=$this->requireField('user_status',20);if(!in_array($status,['active','disabled'],true))throw new HttpException(422,'Статусот на корисникот не е валиден.');$this->db->transaction(function()use($scopeId,$from,$to,$status){$before=$this->db->one("SELECT urs.id,urs.user_id,urs.valid_from,urs.valid_to,u.status,r.code FROM user_role_scopes urs JOIN users u ON u.id=urs.user_id JOIN roles r ON r.id=urs.role_id JOIN directorates d ON d.id=urs.directorate_id WHERE urs.id=:id AND d.code='PRAV' AND COALESCE(r.base_role_code,r.code) IN('senior_legal_officer','legal_officer','legal_assistant')",['id'=>$scopeId]);if(!$before)throw new HttpException(404,'Пристапот на службеникот не е пронајден или не се управува тука.');$this->db->execute('UPDATE user_role_scopes SET valid_from=:from,valid_to=:to,updated_at=SYSUTCDATETIME() WHERE id=:id',['from'=>$from,'to'=>$to,'id'=>$scopeId]);
            // The Legal Office manager controls only the legal scope; the account status of users with other roles stays with the system administrator.
            $otherRoles=(int)$this->db->scalar("SELECT COUNT(*) FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=:user AND urs.id<>:scope AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) AND r.code NOT IN('senior_legal_officer','legal_officer','legal_assistant')",['user'=>$before['user_id'],'scope'=>$scopeId]);
            if($otherRoles===0)$this->db->execute('UPDATE users SET status=:status,updated_at=SYSUTCDATETIME() WHERE id=:id',['status'=>$status,'id'=>$before['user_id']]);elseif($status!==$before['status'])throw new HttpException(422,'Корисникот има и други улоги; статусот на сметката го менува само Администраторот на системот. Користете го периодот на пристап.');
            $this->auth->revokeSessions((int)$before['user_id']);$this->audit->record($this->userId(),'legal_office.user_access_updated','UserRoleScope',$scopeId,$before,['valid_from'=>$from,'valid_to'=>$to,'status'=>$status]);});$this->redirect('/legal-office/users','Пристапот на службеникот е ажуриран.');
    }

    /** POST /cases/{id}/update: number, type, subject, description, priority and status of an administrative case. */
    public function caseUpdate(int $id): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($id,true);
        $number=$this->requireField('case_number',80);$type=$this->requireField('case_type',80);$subject=$this->requireField('subject',250);
        $description=trim((string)$this->request->input('description',''));if(mb_strlen($description)>20000)throw new HttpException(422,'Описот е предолг.');$description=$description!==''?$description:null;
        $priority=$this->requireField('priority',20);if(!in_array($priority,['normal','high','critical'],true))throw new HttpException(422,'Приоритетот не е валиден.');
        $status=$this->requireField('status',30);if(!in_array($status,['open','in_progress','suspended','closed','archived'],true))throw new HttpException(422,'Статусот не е валиден.');
        $reason=$this->requireField('reason',1000);$token=$this->requireField('row_version',18);if(!preg_match('/^0x[0-9a-f]{16}$/i',$token))throw new HttpException(422,'Верзијата не е валидна.');
        $this->db->transaction(function()use($id,$number,$type,$subject,$description,$priority,$status,$reason,$token){
            $before=$this->db->one('SELECT id,is_legal,case_number,case_type,subject,description,priority,status,closed_at,LOWER(sys.fn_varbintohexstr(CONVERT(varbinary(8),row_version))) row_version_token FROM administrative_cases WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$id]);
            if(!$before)throw new HttpException(404,'Предметот не е пронајден.');
            if((bool)$before['is_legal'])throw new HttpException(422,'Предметите на Правната служба се уредуваат во Правната служба.');
            if(!hash_equals(strtolower((string)$before['row_version_token']),strtolower($token)))throw new HttpException(409,'Предметот е изменет од друг корисник. Освежете ја страницата.');
            if($number!==(string)$before['case_number']&&$this->db->scalar('SELECT TOP 1 1 FROM administrative_cases WHERE case_number=:number AND id<>:id',['number'=>$number,'id'=>$id]))throw new HttpException(422,'Веќе постои друг предмет со овој број.');
            $this->db->execute("UPDATE administrative_cases SET case_number=:number,case_type=:type,subject=:subject,description=:description,priority=:priority,status=:status,closed_at=CASE WHEN :closing IN ('closed','archived') THEN COALESCE(closed_at,CONVERT(date,SYSUTCDATETIME())) ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",
                ['number'=>$number,'type'=>$type,'subject'=>$subject,'description'=>$description,'priority'=>$priority,'status'=>$status,'closing'=>$status,'id'=>$id]);
            $auditBefore=$before;unset($auditBefore['row_version_token'],$auditBefore['is_legal']);
            $this->audit->record($this->userId(),'case.updated','AdministrativeCase',$id,$auditBefore,['case_number'=>$number,'case_type'=>$type,'subject'=>$subject,'description'=>$description,'priority'=>$priority,'status'=>$status],$reason);
            $this->db->execute('INSERT INTO case_actions(case_id,action_type,description,action_date,performed_by) VALUES(:case,:type,:description,SYSUTCDATETIME(),:user)',['case'=>$id,'type'=>'CASE_UPDATED','description'=>mb_substr('Предметот е уреден: '.$reason,0,1000),'user'=>$this->userId()]);
        });
        $this->redirect('/cases/'.$id,'Предметот е ажуриран.');
    }

    public function caseCreate(): never
    {
        $number=$this->requireField('case_number',80);$type=$this->requireField('case_type',80);$subject=$this->requireField('subject',250);$description=trim((string)$this->request->input('description',''))?:null;$priority=$this->requireField('priority',20);if(!in_array($priority,['normal','high','critical'],true))throw new HttpException(422,'Приоритетот не е валиден.');$directorate=$this->intField('directorate_id',1,PHP_INT_MAX);if(!$this->scope->allowsDirectorate($this->userId(),$directorate))throw new HttpException(403,'Надвор од вашиот опсег.');if($this->db->scalar('SELECT TOP 1 1 FROM administrative_cases WHERE case_number=:number',['number'=>$number]))throw new HttpException(422,'Веќе постои предмет со овој број.');$id=$this->db->transaction(function()use($number,$type,$subject,$description,$priority,$directorate){$id=$this->db->insertId("INSERT INTO administrative_cases(case_number,case_type,subject,description,originating_directorate_id,responsible_user_id,status,priority,opened_at,created_by,created_at,updated_at) VALUES(:number,:type,:subject,:description,:directorate,:responsible,'open',:priority,CONVERT(date,SYSUTCDATETIME()),:user,SYSUTCDATETIME(),SYSUTCDATETIME())",['number'=>$number,'type'=>$type,'subject'=>$subject,'description'=>$description,'directorate'=>$directorate,'responsible'=>$this->userId(),'user'=>$this->userId(),'priority'=>$priority]);$this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,assigned_by,assigned_at) VALUES(:case,:directorate,'OWNER',:user,SYSUTCDATETIME())",['case'=>$id,'directorate'=>$directorate,'user'=>$this->userId()]);$this->audit->record($this->userId(),'case.created','AdministrativeCase',$id,[],['case_number'=>$number,'subject'=>$subject]);return$id;});$this->redirect('/cases/'.$id,'Предметот е создаден.');
    }

    /**
     * Forwards an administrative case to another directorate with a cover letter (aktpërcjellës): the receiving directorate
     * gets access to the case, its director is notified, and the forwarding counts in the weekly citizen-services report.
     */
    public function caseForward(int $caseId): never
    {
        $case=(new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true);
        if((bool)($case['is_legal']??false))throw new HttpException(422,'Правните предмети ги препраќа Правната служба.');
        $from=$this->intField('from_directorate_id',1,PHP_INT_MAX);$to=$this->intField('to_directorate_id',1,PHP_INT_MAX);
        if($from===$to)throw new HttpException(422,'Секторот што прима мора да биде различен од оној што препраќа.');
        $held=array_map('intval',array_column($this->db->all('SELECT directorate_id FROM case_directorates WHERE case_id=:c',['c'=>$caseId]),'directorate_id'));$held[]=(int)$case['originating_directorate_id'];
        if(!in_array($from,$held,true))throw new HttpException(422,'Предметот не е во секторот што го препраќа.');
        if(!$this->scope->allowsDirectorate($this->userId(),$from))throw new HttpException(403,'Можете да препраќате само од вашиот сектор.');
        $target=$this->db->one('SELECT id,code,name FROM directorates WHERE id=:id AND active=1',['id'=>$to]);if(!$target)throw new HttpException(422,'Секторот што прима не е активен или не постои.');
        $source=$this->db->one('SELECT id,code,name FROM directorates WHERE id=:id',['id'=>$from]);
        $number=$this->requireField('cover_letter_number',100);$date=$this->date('forwarded_on');$reason=trim((string)$this->request->input('reason',''));if(mb_strlen($reason)>2000)throw new HttpException(422,'Причината е предолга.');
        $files=PrivateAttachmentStorage::files($_FILES['cover_letter']??[]);
        $attachment=$files?(new PrivateAttachmentStorage($this->db,$this->audit,$this->userId(),$this->config))->store($files[0],'AdministrativeCase',$caseId,['photo'=>false,'title'=>'Пропратен акт '.$number,'document_type'=>'cover_letter','classification'=>'internal','document_date'=>$date,'latitude'=>null,'longitude'=>null]):null;
        $forwardingId=$this->db->transaction(function()use($caseId,$from,$to,$number,$date,$reason,$attachment,$source,$target):int{
            $id=$this->db->insertId('INSERT INTO case_forwardings(case_id,from_directorate_id,to_directorate_id,cover_letter_number,forwarded_on,reason,attachment_id,forwarded_by) VALUES(:c,:f,:t,:n,:d,:r,:a,:u)',['c'=>$caseId,'f'=>$from,'t'=>$to,'n'=>$number,'d'=>$date,'r'=>$reason!==''?$reason:null,'a'=>$attachment,'u'=>$this->userId()]);
            if(!$this->db->scalar("SELECT TOP 1 1 FROM case_directorates WHERE case_id=:c AND directorate_id=:d AND relationship_type='FORWARDED'",['c'=>$caseId,'d'=>$to]))$this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,assigned_by,assigned_at) VALUES(:c,:d,'FORWARDED',:u,SYSUTCDATETIME())",['c'=>$caseId,'d'=>$to,'u'=>$this->userId()]);
            $this->db->execute("INSERT INTO case_actions(case_id,action_type,description,action_date,performed_by,related_attachment_id) VALUES(:c,'FORWARDED',:d,SYSUTCDATETIME(),:u,:a)",['c'=>$caseId,'d'=>'Препратено со пропратен акт бр. '.$number.' од '.$source['code'].' до '.$target['code'].($reason!==''?': '.$reason:''),'u'=>$this->userId(),'a'=>$attachment]);
            return$id;
        });
        $recipients=$this->directorateHeads($to);
        foreach($recipients as$recipient)$this->notifications->notify($recipient,'case_forwarded','Предмет препратен до вашиот сектор · '.$case['case_number'],['case_id'=>$caseId,'forwarding_id'=>$forwardingId,'url'=>'/cases/'.$caseId,'body'=>'Предмет '.$case['case_number'].' · '.$case['subject'].' е препратен од '.$source['name'].' со пропратен акт бр. '.$number.' ('.$date.'). Отворете го во Управни предмети и потврдете го приемот.']);
        $this->audit->record($this->userId(),'case.forwarded','AdministrativeCase',$caseId,[],['forwarding_id'=>$forwardingId,'from_directorate_id'=>$from,'to_directorate_id'=>$to,'cover_letter_number'=>$number,'forwarded_on'=>$date,'attachment_id'=>$attachment,'notified'=>count($recipients)]);
        $this->redirect('/cases/'.$caseId,'Предметот е препратен до '.$target['name'].'; '.($recipients?'раководителот на секторот е известен.':'секторот нема активен раководител за известување.'));
    }

    /** The receiving directorate confirms it has received a forwarded case. */
    public function caseForwardReceive(int $caseId,int $forwardingId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId);
        $row=$this->db->one('SELECT f.*,d.name to_name FROM case_forwardings f JOIN directorates d ON d.id=f.to_directorate_id WHERE f.id=:id AND f.case_id=:c',['id'=>$forwardingId,'c'=>$caseId]);if(!$row)throw new HttpException(404,'Препраќањето не е пронајдено.');
        if(!$this->scope->allowsDirectorate($this->userId(),(int)$row['to_directorate_id']))throw new HttpException(403,'Приемот го потврдува секторот што прима.');
        if($row['status']==='received')$this->redirect('/cases/'.$caseId,'Приемот е веќе потврден.');
        $this->db->execute("UPDATE case_forwardings SET status='received',received_by=:u,received_at=SYSUTCDATETIME() WHERE id=:id AND status='forwarded'",['u'=>$this->userId(),'id'=>$forwardingId]);
        $this->db->execute("INSERT INTO case_actions(case_id,action_type,description,action_date,performed_by) VALUES(:c,'FORWARD_RECEIVED',:d,SYSUTCDATETIME(),:u)",['c'=>$caseId,'d'=>'Приемот е потврден од '.$row['to_name'].' (пропратен акт бр. '.$row['cover_letter_number'].')','u'=>$this->userId()]);
        $this->notifications->notify((int)$row['forwarded_by'],'case_forward_received','Предметот е примен · пропратен акт бр. '.$row['cover_letter_number'],['case_id'=>$caseId,'forwarding_id'=>$forwardingId,'url'=>'/cases/'.$caseId,'body'=>$row['to_name'].' го потврди приемот на препратениот предмет.']);
        $this->audit->record($this->userId(),'case.forward_received','AdministrativeCase',$caseId,['status'=>'forwarded'],['forwarding_id'=>$forwardingId,'status'=>'received']);
        $this->redirect('/cases/'.$caseId,'Приемот на предметот е потврден.');
    }

    /** Active directors of a directorate; when it has none, its directors' assistants. */
    private function directorateHeads(int $directorateId): array
    {
        foreach([['director'],['directors_assistant']] as$codes){
            $rows=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND COALESCE(r.base_role_code,r.code)=:code AND urs.directorate_id=:d AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['code'=>$codes[0],'d'=>$directorateId]);
            if($rows)return array_map(static fn(array $r): int => (int)$r['id'],$rows);
        }
        return[];
    }

    public function caseDirectorate(int $caseId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true,true);
        $case=$this->db->one('SELECT id,subject FROM administrative_cases WHERE id=:id',['id'=>$caseId]);if(!$case)throw new HttpException(404,'Предметот не е пронајден.');$directorate=$this->intField('directorate_id',1,PHP_INT_MAX);$relationship=$this->requireField('relationship_type',30);if(!in_array($relationship,['RELATED','ACTION_REQUIRED','INFORMED','REVIEWER','EXECUTOR'],true))throw new HttpException(422,'Врската не е валидна.');$due=$this->date('due_at',false);if(!$this->db->scalar('SELECT TOP 1 1 FROM directorates WHERE id=:id AND active=1',['id'=>$directorate]))throw new HttpException(422,'Секторот не е активен или не постои.');if($this->db->scalar('SELECT TOP 1 1 FROM case_directorates WHERE case_id=:case AND directorate_id=:directorate AND relationship_type=:relationship',['case'=>$caseId,'directorate'=>$directorate,'relationship'=>$relationship]))throw new HttpException(422,'Предметот е веќе споделен со овој сектор и со овој вид врска.');$this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,assigned_by,assigned_at,due_at) VALUES(:case,:directorate,:relationship,:user,SYSUTCDATETIME(),:due)",['case'=>$caseId,'directorate'=>$directorate,'relationship'=>$relationship,'user'=>$this->userId(),'due'=>$due]);$users=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id WHERE u.status='active' AND urs.directorate_id=:directorate AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['directorate'=>$directorate]);foreach($users as$row)$this->notifications->notify((int)$row['id'],'case_shared','Нов предмет за секторот · '.$case['subject'],['case_id'=>$caseId,'body'=>'Предмет '.$case['subject'].' е споделен со вашиот сектор со врската '.$relationship.'.']);$this->audit->record($this->userId(),'case.directorate_assigned','AdministrativeCase',$caseId,[],['directorate_id'=>$directorate,'relationship_type'=>$relationship]);$this->redirect('/cases/'.$caseId,'Предметот е споделен со секторот.');
    }

    public function caseTask(int $caseId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true,false);
        $case=$this->db->one('SELECT id,subject FROM administrative_cases WHERE id=:id',['id'=>$caseId]);if(!$case)throw new HttpException(404,'Предметот не е пронајден.');$title=$this->requireField('title',250);$description=trim((string)$this->request->input('description',''))?:null;$assigned=$this->request->integer('assigned_to')?:null;$due=$this->date('due_at');$parent=$this->request->integer('parent_task_id')?:null;if($parent&&!$this->db->scalar('SELECT id FROM case_tasks WHERE id=:id AND case_id=:c',['id'=>$parent,'c'=>$caseId]))throw new HttpException(422,'Подзадачата мора да се поврзе со задача од овој предмет.');if($assigned){$access=new EvidenceAccess($this->db,$this->scope,$this->userId());$access->activeUser($assigned);(new EvidenceAccess($this->db,$this->scope,$assigned))->caseFile($caseId);}$id=$this->db->insertId("INSERT INTO case_tasks(case_id,title,description,assigned_to,due_at,parent_task_id,created_by,created_at,updated_at) VALUES(:case,:title,:description,:assigned,:due,:parent,:user,SYSUTCDATETIME(),SYSUTCDATETIME())",['case'=>$caseId,'title'=>$title,'description'=>$description,'assigned'=>$assigned,'due'=>$due,'parent'=>$parent,'user'=>$this->userId()]);if($assigned)$this->notifications->notify($assigned,'case_task_assigned','Нова задача во предмет · '.$case['subject'],['case_id'=>$caseId,'task_id'=>$id,'body'=>$title.($due?' · Рок '.$due:'')]);$this->audit->record($this->userId(),'case_task.created','CaseTask',$id,[],['case_id'=>$caseId,'title'=>$title]);$this->redirect('/cases/'.$caseId,'Задачата на предметот е додадена.');
    }

    public function caseAction(int $caseId): never
    {
        (new EvidenceAccess($this->db,$this->scope,$this->userId()))->caseFile($caseId,true,false);
        $case=$this->db->one('SELECT id,status FROM administrative_cases WHERE id=:id',['id'=>$caseId]);if(!$case)throw new HttpException(404,'Предметот не е пронајден.');$type=$this->requireField('action_type',50);$description=$this->requireField('description',20000);$newStatus=trim((string)$this->request->input('new_status',''))?:null;if($newStatus&&!in_array($newStatus,['open','in_progress','on_hold','closed','cancelled'],true))throw new HttpException(422,'Неважечки статус.');$this->db->transaction(function()use($caseId,$case,$type,$description,$newStatus){$this->db->execute('INSERT INTO case_actions(case_id,action_type,description,previous_status,new_status,action_date,performed_by) VALUES(:case,:type,:description,:previous,:new_status,SYSUTCDATETIME(),:user)',['case'=>$caseId,'type'=>$type,'description'=>$description,'previous'=>$case['status'],'new_status'=>$newStatus,'user'=>$this->userId()]);if($newStatus)$this->db->execute('UPDATE administrative_cases SET status=:status,closed_at=CASE WHEN :status2=\'closed\' THEN CONVERT(date,SYSUTCDATETIME()) ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id',['status'=>$newStatus,'status2'=>$newStatus,'id'=>$caseId]);});$this->audit->record($this->userId(),'case.action_added','AdministrativeCase',$caseId,[],['action_type'=>$type,'new_status'=>$newStatus]);$this->redirect('/cases/'.$caseId,'Дејството е евидентирано во хронологијата.');
    }

    private function legalCaseData(): array
    {
        $caseType=$this->requireField('case_type',40);$status=$this->requireField('status',30);$phase=$this->requireField('procedural_phase',30);$priority=$this->requireField('priority',20);$confidentiality=$this->requireField('confidentiality',20);
        if(!in_array($caseType,['civil','administrative','labor','property','enforcement','legal_opinion','other'],true))throw new HttpException(422,'Видот на предметот не е валиден.');if(!in_array($status,['new','active','suspended','closed','archived'],true))throw new HttpException(422,'Статусот на предметот не е валиден.');if(!in_array($phase,['first_instance','appeal','revision','retrial','enforcement'],true))throw new HttpException(422,'Процесната фаза не е валидна.');if(!in_array($priority,['normal','high','critical'],true))throw new HttpException(422,'Приоритетот не е валиден.');if(!in_array($confidentiality,['internal','confidential','restricted'],true))throw new HttpException(422,'Доверливоста не е валидна.');
        $directorate=$this->intField('directorate_id',1,PHP_INT_MAX);if(!$this->db->scalar('SELECT TOP 1 1 FROM directorates WHERE id=:id AND active=1',['id'=>$directorate]))throw new HttpException(422,'Поврзаниот сектор не е валиден.');$project=$this->request->integer('project_id')?:null;$procurement=$this->request->integer('procurement_project_id')?:null;$budget=$this->request->integer('budget_line_id')?:null;$this->assertLegalLinks($directorate,$procurement,$budget,$project);$direction=trim((string)$this->request->input('financial_direction',''))?:null;if($direction!==null&&!in_array($direction,['receivable','payable'],true))throw new HttpException(422,'Финансиската насока не е валидна.');$claimed=$this->optionalLegalAmount('claimed_amount');$recognized=$this->optionalLegalAmount('recognized_amount');$realized=$this->optionalLegalAmount('realized_amount');$basis=$recognized??$claimed;if($realized!==null&&($basis===null||(float)$realized>(float)$basis+0.0001))throw new HttpException(422,'Реализираната сума не може да ја надмине признатата или бараната сума.');if(($claimed!==null||$recognized!==null||$realized!==null)&&$direction===null)throw new HttpException(422,'Изберете финансиска насока кога евидентирате суми.');
        $optional=function(string$name,int$max):?string{$value=trim((string)$this->request->input($name,''));if($value==='')return null;if(mb_strlen($value)>$max)throw new HttpException(422,"Област {$name} е предолго.");return$value;};
        return['case_type'=>$caseType,'status'=>$status,'procedural_phase'=>$phase,'priority'=>$priority,'confidentiality'=>$confidentiality,'directorate_id'=>$directorate,'subject'=>$this->requireField('subject',250),'description'=>$optional('description',20000),'external_reference'=>$optional('external_reference',150),'protocol_number'=>$optional('protocol_number',120),'source_name'=>$optional('source_name',200),'court_reference'=>$optional('court_reference',150),'authority_name'=>$optional('authority_name',250),'outcome'=>$optional('outcome',2000),'project_id'=>$project,'procurement_project_id'=>$procurement,'budget_line_id'=>$budget,'financial_direction'=>$direction,'claimed_amount'=>$claimed,'recognized_amount'=>$recognized,'realized_amount'=>$realized];
    }
    private function optionalLegalAmount(string$name): ?string { $value=trim((string)$this->request->input($name,''));if($value==='')return null;if(!preg_match('/^\d{1,15}(?:\.\d{1,4})?$/',$value))throw new HttpException(422,"Износ {$name} не е валиден.");return$value; }
    private function assertLegalLinks(int$directorateId,?int$procurementId,?int$budgetLineId,?int$projectId): void
    {
        if($procurementId!==null&&!$this->db->scalar('SELECT TOP 1 1 FROM procurement_projects WHERE id=:id',['id'=>$procurementId]))throw new HttpException(422,'Предметот на набавката не постои.');if($projectId!==null&&!$this->db->scalar('SELECT TOP 1 1 FROM projects WHERE id=:id AND directorate_id=:directorate',['id'=>$projectId,'directorate'=>$directorateId]))throw new HttpException(422,'Проектот не припаѓа на поврзаниот сектор.');if($budgetLineId===null)return;$zligj=(int)$this->db->scalar("SELECT id FROM directorates WHERE code='PRAV'");$allowed=$this->db->scalar("SELECT TOP 1 1 FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id AND bv.is_official=1 WHERE bl.id=:line AND (bl.directorate_id=:owner OR EXISTS(SELECT 1 FROM budget_line_directorate_access a WHERE a.budget_line_id=bl.id AND a.directorate_id=:legal AND a.can_select=1))",['line'=>$budgetLineId,'owner'=>$directorateId,'legal'=>$zligj]);if(!$allowed)throw new HttpException(422,'Буџетската ставка не е официјална или не е делегирана на Правната служба.');
    }
    private function assertLegalStaff(int$userId): void
    {
        if(!$this->db->scalar("SELECT TOP 1 1 FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id JOIN directorates d ON d.id=urs.directorate_id WHERE u.id=:user AND u.status='active' AND d.code='PRAV' AND COALESCE(r.base_role_code,r.code) IN('legal_office','legal_director','senior_legal_officer','legal_officer','legal_assistant') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['user'=>$userId]))throw new HttpException(422,'Корисникот нема активен пристап до Правната служба.');
    }
    private function notifyLegalCaseBudgetOwner(int$caseId,int$budgetLineId,float$amount,string$direction): void
    {
        $line=$this->db->one('SELECT bl.code,bl.directorate_id FROM budget_lines bl WHERE bl.id=:id',['id'=>$budgetLineId]);if(!$line)return;$label=$direction==='receivable'?'за наплата':'за плаќање';$this->notifyDirectorateBudgetImpact((int)$line['directorate_id'],'Правен предмет #'.$caseId.' е поврзан со буџетската шифра '.$line['code'].' со '.number_format($amount,2,'.',',').' ден. '.$label.'. Доверливата содржина не е вклучена во ова известување.');
    }

    private function riskCategory(): string { $category=$this->requireField('category',60);$this->assertRiskCategory($category);return$category; }
    private function assertRiskCategory(string$category,?string$legacy=null): void { $allowed=['strategic','financial_budget','operational','procurement_contract','legal_compliance','schedule_delivery','technical_digital','human_resources','reputational_stakeholder','environment_safety'];if(!in_array($category,$allowed,true)&&$category!==$legacy)throw new HttpException(422,'Изберете валидна категорија од листата.'); }
    private function assertRiskProject(?int $projectId,int $directorateId): void { if($projectId===null)return;if(!$this->db->scalar('SELECT TOP 1 1 FROM projects WHERE id=:project AND directorate_id=:directorate',['project'=>$projectId,'directorate'=>$directorateId]))throw new HttpException(422,'Избраниот проект не постои или не припаѓа на секторот на ризикот.'); }
    private function riskMeasureData(int$directorateId,bool$allowLegacyStatus=false): array
    {
        $type=$this->requireField('measure_type',20);if(!in_array($type,['prevent','reduce','transfer','avoid','contingency'],true))throw new HttpException(422,'Видот на мерката не е валиден.');$action=$this->requireField('action',10000);$owner=$this->request->integer('owner_id')?:null;if($owner!==null&&!$this->db->scalar("SELECT TOP 1 1 FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.id=:owner AND u.status='active' AND (urs.directorate_id=:directorate OR COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','system_admin')) AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['owner'=>$owner,'directorate'=>$directorateId]))throw new HttpException(422,'Одговорното лице нема активен пристап до овој сектор.');$due=$this->date('due_at',false);$cost=trim((string)$this->request->input('cost',''))===''?null:$this->nonNegativeAmount('cost');$status=$this->requireField('status',20);$statuses=['planned','in_progress','completed','cancelled'];if($allowLegacyStatus)$statuses[]='open';if(!in_array($status,$statuses,true))throw new HttpException(422,'Статусот на мерката не е валиден.');$probabilityReduction=$this->intField('probability_reduction',0,4);$impactReduction=$this->intField('impact_reduction',0,4);if($probabilityReduction===0&&$impactReduction===0)throw new HttpException(422,'Мерката мора да ја намали барем веројатноста или влијанието за еден поен.');$evidence=trim((string)$this->request->input('evidence',''))?:null;if($evidence!==null&&mb_strlen($evidence)>10000)throw new HttpException(422,'Доказот е предолг.');if($status==='completed'&&$evidence===null)throw new HttpException(422,'За завршена мерка мора да се евидентира доказ или резултат.');return['action'=>$action,'type'=>$type,'owner'=>$owner,'due'=>$due,'cost'=>$cost,'status'=>$status,'probability_reduction'=>$probabilityReduction,'impact_reduction'=>$impactReduction,'evidence'=>$evidence];
    }
    private function riskResidual(int$riskId,int$probability,int$impact): array { $reductions=$this->db->one("SELECT COALESCE(SUM(probability_reduction),0) probability_reduction,COALESCE(SUM(impact_reduction),0) impact_reduction FROM risk_actions WITH (UPDLOCK,HOLDLOCK) WHERE risk_id=:risk AND status='completed'",['risk'=>$riskId])??[];$residualProbability=max(1,$probability-(int)($reductions['probability_reduction']??0));$residualImpact=max(1,$impact-(int)($reductions['impact_reduction']??0));return[$residualProbability,$residualImpact,$residualProbability*$residualImpact]; }
    private function syncRiskResidual(int$riskId): array { $risk=$this->db->one('SELECT probability,impact FROM risks WITH (UPDLOCK,HOLDLOCK) WHERE id=:id',['id'=>$riskId]);if(!$risk)throw new HttpException(404,'Ризикот не е пронајден.');$residual=$this->riskResidual($riskId,(int)$risk['probability'],(int)$risk['impact']);$this->db->execute('UPDATE risks SET residual_probability=:probability,residual_impact=:impact,residual_score=:score,updated_at=SYSUTCDATETIME() WHERE id=:id',['probability'=>$residual[0],'impact'=>$residual[1],'score'=>$residual[2],'id'=>$riskId]);return['probability'=>$residual[0],'impact'=>$residual[1],'score'=>$residual[2]]; }

    private function postedIds(string $name): array
    {
        $raw=$_POST[$name]??[];if(!is_array($raw))throw new HttpException(422,'Изборот не е валиден.');if(count($raw)>500)throw new HttpException(422,'Избрани се премногу точки.');$ids=[];foreach($raw as$value){if(!is_string($value)&&!is_int($value))throw new HttpException(422,'Изборот не е валиден.');$value=(string)$value;if(!preg_match('/^[1-9]\d*$/',$value))throw new HttpException(422,'Изборот содржи невалидни ID.');$ids[]=(int)$value;}return array_values(array_unique($ids));
    }
    private function assertAgendaEntities(string $table,array $ids,int $directorateId): void
    {
        if(!$ids)return;if(!in_array($table,['projects','risks'],true))throw new \LogicException('Invalid agenda entity table.');$marks=[];$params=['directorate'=>$directorateId];foreach($ids as$i=>$id){$key='id'.$i;$marks[]=':'.$key;$params[$key]=$id;}$count=(int)$this->db->scalar('SELECT COUNT(*) FROM '.$table.' WHERE directorate_id=:directorate AND id IN ('.implode(',',$marks).')',$params);if($count!==count($ids))throw new HttpException(422,'Една или повеќе точки не припаѓаат на овој сектор.');
    }
    private function closeMayorAgendaItem(int $itemId,string $status): void { $this->db->execute('UPDATE mayor_agenda_items SET status=:status,resolved_by=:user,resolved_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id AND status=:open',['status'=>$status,'user'=>$this->userId(),'id'=>$itemId,'open'=>'open']); }
    private function notifyMayors(string $subject,array $payload): void { $rows=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");foreach($rows as$row)if((int)$row['id']!==$this->userId())$this->notifications->notify((int)$row['id'],'mayor_agenda_updated',$subject,$payload); }

    private function notifyEnforcementDirectorates(string $batchId): void
    {
        $rows=$this->db->all('SELECT d.id,d.code,SUM(ee.net_amount) net_amount,COUNT(*) case_count FROM enforcement_executions ee JOIN budget_lines bl ON bl.id=ee.budget_line_id JOIN directorates d ON d.id=bl.directorate_id WHERE ee.import_batch_id=:batch GROUP BY d.id,d.code',['batch'=>$batchId]);
        foreach($rows as$row)$this->notifyDirectorateBudgetImpact((int)$row['id'],'Увоз '.$batchId.' има засегнато '.(int)$row['case_count'].' предмети и '.number_format((float)$row['net_amount'],2,'.',',').' МКД во буџетските ставки на секторот '.(string)$row['code'].'.');
    }

    private function notifyDirectorateBudgetImpact(int $directorateId,string $body): void
    {
        try{
            $users=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id WHERE u.status='active' AND urs.directorate_id=:directorate AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['directorate'=>$directorateId]);
            foreach($users as$user)$this->notifications->notify((int)$user['id'],'enforcement_budget_impact','Известување за буџетската ставка #'.$directorateId.' · '.gmdate('Y-m-d H:i:s'),['body'=>$body,'directorate_id'=>$directorateId,'url'=>'/executed-cases']);
        }catch(\Throwable$exception){$this->logger->write('error','enforcement_execution.notification_failed',['directorate_id'=>$directorateId,'error'=>$exception->getMessage()]);}
    }

    private function notifyLegalOffice(int $executionId): void
    {
        try{
            $users=$this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id JOIN directorates d ON d.id=urs.directorate_id WHERE u.status='active' AND d.code='PRAV' AND COALESCE(r.base_role_code,r.code) IN('legal_office','legal_director','senior_legal_officer','legal_officer','legal_assistant') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");
            foreach($users as$user)$this->notifications->notify((int)$user['id'],'enforcement_needs_reclassification','Предмет #'.$executionId.' бара правна прекласификација',['body'=>'Финансии го променија износот на предметот. Распределбата на главница, казни, такси и камати треба повторно да се прегледа.','execution_id'=>$executionId,'url'=>'/executed-cases/'.$executionId]);
        }catch(\Throwable$exception){$this->logger->write('error','enforcement_execution.legal_notification_failed',['execution_id'=>$executionId,'error'=>$exception->getMessage()]);}
    }

    /**
     * Password policy: at least 14 characters, a lowercase letter, an uppercase letter, a number and a symbol. The
     * message names exactly what is missing (Albanian letters such as ë and ç count as letters and as one character).
     */
    public static function passwordProblems(string $password): array
    {
        $missing = [];
        if (mb_strlen($password) < 14) $missing[] = 'најмалку 14 знаци (сега '.mb_strlen($password).')';
        if (!preg_match('/\p{Ll}/u', $password)) $missing[] = 'мала буква';
        if (!preg_match('/\p{Lu}/u', $password)) $missing[] = 'голема буква';
        if (!preg_match('/\d/', $password)) $missing[] = 'број';
        if (!preg_match('/[^\p{L}\p{N}]/u', $password)) $missing[] = 'симбол (на пр. $ . ! @ #)';
        return $missing;
    }

    private static function assertPasswordPolicy(string $password): void
    {
        $missing = self::passwordProblems($password);
        if ($missing !== []) throw new HttpException(422, 'На лозинката ѝ недостасува: '.implode(', ', $missing).'. Потребни се најмалку 14 знаци, мала буква, голема буква, број и симбол.');
    }

    private function userIdentity(): array
    {
        $name=$this->requireField('name',255);$username=mb_strtolower($this->requireField('username',100));$email=mb_strtolower($this->requireField('email',255));$password=$this->requireField('password');$confirmation=$this->requireField('password_confirmation');
        if(!preg_match('/^[a-z0-9._-]{3,100}$/',$username))throw new HttpException(422,'Корисничкото име мора да има 3-100 знаци: a-z, 0-9, точка, _ или -.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpException(422,'Е-поштата не е валидна.');
        if($password!==$confirmation)throw new HttpException(422,'Лозинката и нејзиното повторување не се совпаѓаат. Напишете ја истата лозинка во двете полиња.');
        self::assertPasswordPolicy($password);
        $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);if($hash===false)throw new \RuntimeException('Генерирањето на хашот на лозинката не успеа.');$password=$confirmation='';return[$name,$username,$email,$hash];
    }
    private function assertUniqueIdentity(string $username,string $email): void { if($this->db->scalar('SELECT TOP 1 1 FROM users WHERE LOWER(username)=:username OR LOWER(email)=:email',['username'=>$username,'email'=>$email]))throw new HttpException(422,'Корисничкото име или е-поштата веќе постојат.'); }
    private function roleScope(bool $required): array
    {
        $roleId=$this->request->integer('role_id');if($roleId<=0){if($required)throw new HttpException(422,'Улогата е задолжителна.');return[null,null];}$role=$this->db->one('SELECT id,code,name,COALESCE(base_role_code,code) behaviour_code FROM roles WHERE id=:id AND active=1',['id'=>$roleId]);if(!$role)throw new HttpException(422,'Улогата не е валидна или е неактивна.');$directorateId=$this->request->integer('directorate_id')?:null;$global=in_array($role['behaviour_code'],['mayor','mayor_admin','deputy_mayor','finance','procurement_officer','procurement_manager','legal_office','system_admin'],true);if($global)$directorateId=null;elseif($directorateId===null)throw new HttpException(422,'Изберете сектор за оваа улога.');elseif(!$this->db->scalar('SELECT TOP 1 1 FROM directorates WHERE id=:id AND active=1',['id'=>$directorateId]))throw new HttpException(422,'Секторот не е валиден.');return[$role,$directorateId];
    }
    private function taskData(int$projectId,int$directorateId): array
    {
        $title=$this->requireField('title',250);$owner=$this->request->integer('owner_id')?:null;if($owner!==null&&!$this->db->scalar("SELECT TOP 1 1 FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.id=:owner AND u.status='active' AND (urs.directorate_id=:directorate OR COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','finance','system_admin')) AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",['owner'=>$owner,'directorate'=>$directorateId]))throw new HttpException(422,'Одговорното лице нема активен пристап до овој сектор.');$starts=$this->date('starts_at',false);$due=$this->date('due_at',false);if($starts&&$due&&$due<$starts)throw new HttpException(422,'Рокот на задачата мора да биде на или по датумот на почеток.');$status=$this->requireField('status',30);if(!in_array($status,['not_started','in_progress','blocked','done','cancelled'],true))throw new HttpException(422,'Статусот на задачата не е валиден.');$progress=$this->intField('progress',0,100);if($status==='done'&&$progress!==100)throw new HttpException(422,'Завршената задача мора да има напредок 100%.');if($status==='not_started'&&$progress!==0)throw new HttpException(422,'Незапочнатата задача мора да има напредок 0%.');$weightRaw=trim((string)$this->request->input('weight_percent',''));if(!preg_match('/^(?:\d{1,2}(?:\.\d{1,2})?|100(?:\.0{1,2})?)$/',$weightRaw))throw new HttpException(422,'Тежината мора да биде од 0 до 100 со најмногу две децимали.');$weight=number_format((float)$weightRaw,2,'.','');$next=trim((string)$this->request->input('next_step',''))?:null;$notes=trim((string)$this->request->input('dependency_notes',''))?:null;if(($next!==null&&mb_strlen($next)>5000)||($notes!==null&&mb_strlen($notes)>5000))throw new HttpException(422,'Следниот чекор или белешките се предолги.');return['title'=>$title,'owner'=>$owner,'starts'=>$starts,'due'=>$due,'status'=>$status,'progress'=>$progress,'weight'=>$weight,'next'=>$next,'notes'=>$notes];
    }
    /**
     * Scales the weights of the project's other tasks proportionally so that, with $weight for the new or edited task, the
     * total is 100%. Only lowers weights (does nothing when the total stays within 100%). Runs in the caller's transaction.
     */
    private function rebalanceTaskWeights(int $projectId,float $weight,?int $excludeId): void
    {
        $others=$this->db->all('SELECT id,weight_percent FROM project_tasks WITH (UPDLOCK,HOLDLOCK) WHERE project_id=:project'.($excludeId!==null?' AND id<>:exclude':'').' ORDER BY weight_percent DESC,id',['project'=>$projectId]+($excludeId!==null?['exclude'=>$excludeId]:[]));
        $sum=array_sum(array_map(static fn(array $r): float => (float)$r['weight_percent'],$others));$target=max(0.0,100.0-$weight);
        if($sum<=$target+0.00001||$sum<=0)return;
        $new=[];foreach($others as$r)$new[(int)$r['id']]=floor((float)$r['weight_percent']*$target/$sum*100)/100;
        $rest=round($target-array_sum($new),2);if($rest>0&&$new){$first=array_key_first($new);$new[$first]=round($new[$first]+$rest,2);}
        $before=[];foreach($others as$r){$before[(int)$r['id']]=(string)$r['weight_percent'];$this->db->execute('UPDATE project_tasks SET weight_percent=:weight,updated_at=SYSUTCDATETIME() WHERE id=:id',['weight'=>number_format($new[(int)$r['id']],2,'.',''),'id'=>(int)$r['id']]);}
        $this->audit->record($this->userId(),'project_task.weights_rebalanced','App\\Models\\Project',$projectId,['weights'=>$before],['weights'=>$new,'new_task_weight'=>$weight]);
    }
    private function assertTaskWeight(int$projectId,string$weight,?int$excludeId): void { $sql='SELECT COALESCE(SUM(weight_percent),0) FROM project_tasks WHERE project_id=:project';$params=['project'=>$projectId];if($excludeId!==null){$sql.=' AND id<>:exclude';$params['exclude']=$excludeId;}$used=(float)$this->db->scalar($sql,$params);if($used+(float)$weight>100.00001)throw new HttpException(422,'Вкупната тежина на задачите на проектот не може да надмине 100%. Моментално искористено: '.number_format($used,2). '%.'); }
    private function storeInitialEvidence(int$projectId,?int$taskId=null): int
    {
        $documents=PrivateAttachmentStorage::files($_FILES['documents']??[]);$photos=PrivateAttachmentStorage::files($_FILES['photos']??[]);if(!$documents&&!$photos)return 0;
        $storage=new PrivateAttachmentStorage($this->db,$this->audit,$this->userId(),$this->config);$type=$taskId===null?'App\\Models\\Project':'App\\Models\\ProjectTask';$entityId=$taskId??$projectId;$count=0;
        foreach($documents as$file){$storage->store($file,$type,$entityId,['title'=>$this->request->input('initial_document_title',''),'document_type'=>$this->request->input('initial_document_type','other'),'classification'=>$this->request->input('initial_document_classification','internal'),'document_date'=>$this->request->input('initial_document_date','')],$projectId,$taskId);$count++;}
        foreach($photos as$file){$storage->store($file,$type,$entityId,['photo'=>true,'title'=>$this->request->input('initial_photo_caption',''),'document_date'=>$this->request->input('initial_photo_date','')],$projectId,$taskId);$count++;}
        return$count;
    }
    private function syncProjectProgress(int$projectId,?int$fallback=null): int { $weight=(float)$this->db->scalar('SELECT COALESCE(SUM(weight_percent),0) FROM project_tasks WHERE project_id=:project',['project'=>$projectId]);if($weight>0)$progress=(int)round((float)$this->db->scalar('SELECT COALESCE(SUM(weight_percent*progress)/100.0,0) FROM project_tasks WHERE project_id=:project',['project'=>$projectId]));else$progress=$fallback??(int)$this->db->scalar('SELECT progress FROM projects WHERE id=:id',['id'=>$projectId]);$progress=max(0,min(100,$progress));$this->db->execute('UPDATE projects SET progress=:progress,updated_at=SYSUTCDATETIME() WHERE id=:id',['progress'=>$progress,'id'=>$projectId]);return$progress; }
    private function lockedAllocation(int $id): array { $row=$this->db->one('SELECT ba.*,bl.id budget_line_id,bl.directorate_id,p.directorate_id project_directorate_id FROM budget_allocations ba WITH (UPDLOCK,HOLDLOCK) JOIN budget_lines bl ON bl.id=ba.budget_line_id LEFT JOIN projects p ON p.id=ba.project_id WHERE ba.id=:id',['id'=>$id]);if(!$row)throw new HttpException(404,'Распределбата не е пронајдена.');return $row; }
    private function allocationWithLine(int $id): array { $row=$this->db->one('SELECT ba.*,bl.id budget_line_id,bl.directorate_id,p.directorate_id project_directorate_id,COALESCE(p.directorate_id,bl.directorate_id) operational_directorate_id FROM budget_allocations ba JOIN budget_lines bl ON bl.id=ba.budget_line_id LEFT JOIN projects p ON p.id=ba.project_id WHERE ba.id=:id',['id'=>$id]);if(!$row)throw new HttpException(404,'Распределбата не е пронајдена.');return $row; }
    private function assertFinancial(array $allocation): void { if($this->scope->allowsFinancial($this->userId(),(int)$allocation['directorate_id']))return;$projectDirectorate=(int)($allocation['project_directorate_id']??0);if($projectDirectorate>0&&$this->scope->allowsFinancial($this->userId(),$projectDirectorate)&&$this->scope->budgetLineAllowsDirectorate((int)$allocation['budget_line_id'],(int)$allocation['directorate_id'],$projectDirectorate,'select'))return;throw new HttpException(403,'Немате финансиски пристап до оваа распределба.'); }
    private function available(int $id): string { return (string)$this->db->scalar("SELECT CAST(ba.current_amount-COALESCE((SELECT SUM(r.amount) FROM reservations r WHERE r.budget_allocation_id=ba.id AND r.status='active'),0)-COALESCE((SELECT SUM(c.amount) FROM commitments c WHERE c.budget_allocation_id=ba.id AND c.status='active'),0) AS decimal(19,4)) FROM budget_allocations ba WHERE ba.id=:id",['id'=>$id]); }
    private function transactionRow(int $allocation,string $type,string $amount,string $direction,string $referenceType,int $referenceId,?string $key=null): void { $key=$key??($_SERVER['HTTP_IDEMPOTENCY_KEY']??Request::uuid());$this->db->execute('IF NOT EXISTS (SELECT 1 FROM budget_transactions WHERE idempotency_key=:lookup_key) INSERT INTO budget_transactions (budget_allocation_id,type,amount,direction,reference_type,reference_id,idempotency_key,posted_by,posted_at,created_at,updated_at) VALUES (:a,:type,:amount,:direction,:reference,:rid,:insert_key,:u,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())',['a'=>$allocation,'type'=>$type,'amount'=>$amount,'direction'=>$direction,'reference'=>$referenceType,'rid'=>$referenceId,'lookup_key'=>$key,'insert_key'=>$key,'u'=>$this->userId()]); }
    private function nonNegativeAmount(string $name): string { $v=trim((string)$this->request->input($name));if(!preg_match('/^\d{1,15}(?:\.\d{1,4})?$/',$v))throw new HttpException(422,"Износ {$name} не е валиден.");return $v; }
    private function uploadLimitBytes(): int { $parse=static function(string$v):int{$v=trim($v);$n=(int)$v;$s=strtolower(substr($v,-1));return $s==='g'?$n*1073741824:($s==='m'?$n*1048576:($s==='k'?$n*1024:$n));};return min((int)($this->config['uploads']['requested_max_mb']??20)*1048576,$parse((string)ini_get('upload_max_filesize')),$parse((string)ini_get('post_max_size'))); }
    private function uploadError(int $code): string { return match($code){UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'Датотеката го надминува лимитот на серверот.',UPLOAD_ERR_NO_FILE=>'Изберете датотека.',default=>'Прикачувањето не успеа со код '.$code.'.'}; }
}
