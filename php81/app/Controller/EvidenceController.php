<?php
declare(strict_types=1);
namespace EKabineti\Controller;
use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\Pagination;
use EKabineti\Core\View;
use EKabineti\Service\EvidenceAccess;
use EKabineti\Service\GeoReference;
use EKabineti\Service\PrivateAttachmentStorage;

final class EvidenceController extends Controller
{
    private function access(): EvidenceAccess {return new EvidenceAccess($this->db,$this->scope,$this->userId());}
    private function view(): View {return new View($this->auth,$this->scope);}
    private function page(string $title,string $body): never {$this->view()->render($title,$body);}
    private function form(string $url,string $body): string {return '<form method="post" action="'.$url.'" class="form-grid">'.$this->view()->csrf().$body.'</form>';}
    private function users(): string {$s='';foreach($this->db->all("SELECT id,name FROM users WHERE status='active' ORDER BY name") as $u)$s.='<option value="'.$u['id'].'">'.View::e($u['name']).'</option>';return $s;}

    public function upload(?int $project=null): never
    {
        $kind=$project!==null?'project':$this->requireField('entity_type',30);
        $id=$project??$this->intField('entity_id',1,PHP_INT_MAX);
        $type=match($kind){'project'=>'App\\Models\\Project','case'=>'AdministrativeCase','risk'=>'App\\Models\\Risk','budget_change'=>'App\\Models\\BudgetChangeRequest',default=>throw new HttpException(422,'Неважечки ентитет.')};
        $this->access()->entity($type,$id,true);
        $photo=$project!==null;
        $title=$photo?trim((string)$this->request->input('caption','')):trim((string)$this->request->input('title',''));
        $category=$photo?'photo':(string)$this->request->input('document_type','other');
        $classification=$photo?'internal':(string)$this->request->input('classification','internal');
        $geo=GeoReference::parse($_POST);$files=PrivateAttachmentStorage::files($_FILES['files']??($_FILES['file']??[]));
        if(!$files)throw new HttpException(422,'Изберете барем една датотека.');
        $storage=new PrivateAttachmentStorage($this->db,$this->audit,$this->userId(),$this->config);
        foreach($files as$file){$attachment=$storage->store($file,$type,$id,['photo'=>$photo,'title'=>$title,'document_type'=>$category,'classification'=>$classification,'document_date'=>$this->request->input('document_date',''),'latitude'=>$geo['latitude'],'longitude'=>$geo['longitude']],$photo?$id:null);if($type==='AdministrativeCase')$this->timeline($id,'DOCUMENT_UPLOADED','Документ: '.($title?:basename((string)$file['name'])),null,$attachment);}
        $casePath='/cases/'.$id;if($kind==='case'&&(bool)$this->db->scalar('SELECT TOP 1 is_legal FROM administrative_cases WHERE id=:id',['id'=>$id]))$casePath='/legal-office/cases/'.$id;$this->redirect($kind==='case'?$casePath:($kind==='project'?'/projects/'.$id:'/'),count($files).' датотеки се зачувани приватно и чекаат скенирање.');
    }

    public function download(int $id): never
    {
        $a=$this->db->one('SELECT * FROM attachments WHERE id=:id',['id'=>$id]);if(!$a)throw new HttpException(404,'Документот не е пронајден.');
        $this->access()->entity($a['entity_type'],(int)$a['entity_id']);
        if($a['scan_status']!=='clean')throw new HttpException(423,'Документот чека скенирање или е блокиран.');
        $root=realpath(APP_ROOT.'/storage/private');$path=realpath(APP_ROOT.'/storage/private/'.$a['storage_key']);
        if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path))throw new HttpException(404,'Датотеката не е пронајдена.');
        if(!hash_equals($a['checksum'],hash_file('sha256',$path)))throw new HttpException(423,'Интегритетот на документот не се совпаѓа.');
        $inline=$this->request->input('inline')==='1'&&in_array($a['mime_type'],['image/jpeg','image/png'],true);
        header('Cache-Control: private, no-store');header('Content-Type: '.$a['mime_type']);$ascii=preg_replace('/[^A-Za-z0-9._-]+/','_',(string)$a['original_name'])?:'document';header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode((string)$a['original_name']));header('Content-Length: '.filesize($path));
        $this->audit->record($this->userId(),'attachment.downloaded','Attachment',$id,[],[]);
        readfile($path);exit;
    }

    public function shared(): never
    {
        $body='<section class="card"><h2>Проекти споделени со мене</h2>';
        foreach($this->db->all('SELECT p.id,p.name,a.access_level FROM project_user_access a JOIN projects p ON p.id=a.project_id WHERE a.user_id=:u',['u'=>$this->userId()]) as $r)$body.='<p><a href="/projects/'.$r['id'].'">'.View::e($r['name']).'</a> · '.View::e($r['access_level']).'</p>';
        $body.='<h2>Задачи споделени со мене</h2>';
        foreach($this->db->all('SELECT t.id,t.title,a.access_level FROM task_user_access a JOIN project_tasks t ON t.id=a.task_id WHERE a.user_id=:u',['u'=>$this->userId()]) as $r)$body.='<p><a href="/shared/tasks/'.$r['id'].'">'.View::e($r['title']).'</a> · '.View::e($r['access_level']).'</p>';
        $this->page('Споделени со мене',$body.'</section>');
    }

    public function sharedTask(int $id): never
    {
        $t=$this->access()->task($id,$this->request->method==='POST');
        if($this->request->method==='POST'){
            $progress=$this->intField('progress',0,100);$status=$this->requireField('status',30);if(!in_array($status,['not_started','in_progress','done','blocked'],true))throw new HttpException(422,'Неважечки статус.');
            if(($status==='done')!==($progress===100))throw new HttpException(422,'Завршената задача мора да има напредок 100%.');
            $this->db->transaction(function()use($id,$t,$progress,$status){
                $this->db->execute('UPDATE project_tasks SET status=:s,progress=:p,updated_at=SYSUTCDATETIME() WHERE id=:id',['s'=>$status,'p'=>$progress,'id'=>$id]);
                // Only weighted tasks drive project progress; without weights the last reported progress is kept.
                $this->db->execute('UPDATE projects SET progress=CONVERT(int,ROUND((SELECT COALESCE(SUM(weight_percent*progress/100.0),0) FROM project_tasks WHERE project_id=:pid),0)),updated_at=SYSUTCDATETIME() WHERE id=:id AND EXISTS(SELECT 1 FROM project_tasks WHERE project_id=:wid AND weight_percent>0)',['pid'=>$t['project_id'],'id'=>$t['project_id'],'wid'=>$t['project_id']]);
                $this->audit->record($this->userId(),'task.shared_updated','ProjectTask',$id,$t,['status'=>$status,'progress'=>$progress]);
            });$this->redirect('/shared/tasks/'.$id,'Задачата е ажурирана.');
        }
        $body='<section class="card"><h2>'.View::e($t['title']).'</h2><p>Рок: '.View::e($t['due_at']).' · Напредок: '.(int)$t['progress'].'%</p>';
        try{$this->access()->task($id,true);$body.=$this->form('/shared/tasks/'.$id,'<label>Напредок<input type="number" min="0" max="100" name="progress" value="'.(int)$t['progress'].'" required></label><label>Статус<select name="status"><option value="not_started"'.($t['status']==='not_started'?' selected':'').'>Не е започната</option><option value="in_progress"'.($t['status']==='in_progress'?' selected':'').'>Во тек</option><option value="done"'.($t['status']==='done'?' selected':'').'>Завршена</option><option value="blocked"'.($t['status']==='blocked'?' selected':'').'>Блокирано</option></select></label><button>Зачувај</button>');}catch(HttpException $e){}
        $this->page('Споделена задача',$body.'</section>');
    }

    public function linkCreate(): never
    {
        $this->access()->leadership();$type=$this->requireField('entity_type',100);$id=$this->intField('entity_id',1,PHP_INT_MAX);$this->access()->entity($type,$id);
        $token=bin2hex(random_bytes(32));$linkId=$this->db->insertId('INSERT INTO document_access_links(token_hash,entity_type,entity_id,created_by,expires_at,created_at) VALUES(:hash,:type,:entity,:user,DATEADD(day,7,SYSUTCDATETIME()),SYSUTCDATETIME())',['hash'=>hash('sha256',$token),'type'=>$type,'entity'=>$id,'user'=>$this->userId()]);
        $this->audit->record($this->userId(),'document_link.created','DocumentAccessLink',$linkId,[],['entity_type'=>$type,'entity_id'=>$id]);
        $this->page('Врска до документите','<section class="card"><p>Важи 7 дена. Бара најава како раководител на сектор или Градоначалник и пристап до објектот.</p><a href="/document-links/'.$token.'">Отвори ги документите</a>'.$this->form('/document-links/'.$linkId.'/revoke','<button>Отповикај ја врската</button>').'</section>');
    }

    public function linkView(string $token): never
    {
        $this->access()->leadership();
        $link=$this->db->one('SELECT * FROM document_access_links WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>SYSUTCDATETIME()',['hash'=>hash('sha256',$token)]);
        if(!$link)throw new HttpException(404,'Врската е истечена или е отповикана.');$this->access()->entity($link['entity_type'],(int)$link['entity_id']);
        $body='<section class="card"><h2>Документи</h2>';
        $attachments=$link['entity_type']==='App\\Models\\Project'?$this->db->all("SELECT a.id,a.title,a.original_name,a.scan_status FROM attachments a LEFT JOIN project_tasks t ON a.entity_type='App\\Models\\ProjectTask' AND t.id=a.entity_id WHERE (a.entity_type='App\\Models\\Project' AND a.entity_id=:id) OR t.project_id=:project",['id'=>$link['entity_id'],'project'=>$link['entity_id']]):$this->db->all('SELECT id,title,original_name,scan_status FROM attachments WHERE entity_type=:type AND entity_id=:id',['type'=>$link['entity_type'],'id'=>$link['entity_id']]);
        foreach($attachments as $a)$body.='<p>'.($a['scan_status']==='clean'?'<a href="/attachments/'.$a['id'].'">'.View::e($a['title']?:$a['original_name']).'</a>':View::e($a['original_name']).' · '.View::e($a['scan_status'])).'</p>';
        $body.=$this->form('/document-links/'.$link['id'].'/revoke','<button>Отповикај ја врската</button>');
        $this->page('Споделени документи',$body.'</section>');
    }

    public function linkRevoke(int $id): never
    {
        $this->access()->leadership();$l=$this->db->one('SELECT * FROM document_access_links WHERE id=:id',['id'=>$id]);if(!$l)throw new HttpException(404,'Врската не е пронајдена.');
        if((int)$l['created_by']!==$this->userId()&&!in_array('mayor',$this->scope->roles($this->userId()),true))throw new HttpException(403,'Не можете да ја отповикате оваа врска.');
        $this->db->execute('UPDATE document_access_links SET revoked_at=SYSUTCDATETIME() WHERE id=:id',['id'=>$id]);$this->audit->record($this->userId(),'document_link.revoked','DocumentAccessLink',$id,[],[]);$this->redirect('/shared','Врската е отповикана.');
    }

    public function caseUser(int $id): never
    {
        $case=$this->access()->caseFile($id,true,true);$user=$this->intField('user_id',1,PHP_INT_MAX);$this->access()->activeUser($user);
        if(!$this->db->scalar('SELECT 1 FROM case_user_access WHERE case_id=:c AND user_id=:u',['c'=>$id,'u'=>$user]))$this->db->execute('INSERT INTO case_user_access(case_id,user_id,granted_by) VALUES(:c,:u,:g)',['c'=>$id,'u'=>$user,'g'=>$this->userId()]);
        $this->timeline($id,'USER_ASSIGNED','Предметот е споделен со корисникот #'.$user);$this->audit->record($this->userId(),'case.user_shared','AdministrativeCase',$id,[],['user_id'=>$user]);
        $this->notifications->notify($user,'case_shared','Предмет #'.$id.' · '.$case['subject'],['body'=>'Определени сте на предметот '.$case['subject'],'url'=>'/cases/'.$id]);$this->redirect('/cases/'.$id,'Предметот е споделен со корисникот.');
    }

    public function caseTaskStatus(int $case,int $task): never
    {
        $this->access()->caseFile($case,true);$t=$this->db->one('SELECT * FROM case_tasks WHERE id=:id AND case_id=:c',['id'=>$task,'c'=>$case]);if(!$t)throw new HttpException(404,'Задачата не е пронајдена.');
        $status=$this->requireField('status',30);if(!in_array($status,['open','in_progress','completed','blocked'],true))throw new HttpException(422,'Неважечки статус.');
        $this->db->transaction(function()use($case,$task,$status,$t){
            if($status==='completed'&&$this->db->scalar("SELECT 1 FROM case_tasks WHERE parent_task_id=:p AND status<>'completed'",['p'=>$task]))throw new HttpException(422,'Најпрво завршете ги подзадачите.');
            $this->db->execute("UPDATE case_tasks SET status=:s,completed_at=CASE WHEN :done='completed' THEN SYSUTCDATETIME() ELSE NULL END,updated_at=SYSUTCDATETIME() WHERE id=:id",['s'=>$status,'done'=>$status,'id'=>$task]);$this->timeline($case,'TASK_STATUS','Задача #'.$task.': '.$status);$this->audit->record($this->userId(),'case_task.status_changed','CaseTask',$task,['status'=>$t['status']],['status'=>$status]);
        });$this->redirect('/cases/'.$case,'Статусот на задачата е зачуван.');
    }

    public function act(int $case): never
    {
        $this->access()->caseFile($case,true);$number=$this->requireField('act_number',100);$type=$this->requireField('act_type',60);$date=$this->date('act_date');$effective=$this->date('effective_date',false);$description=$this->requireField('description',20000);$geo=GeoReference::parse($_POST);
        $attachment=$this->request->integer('attachment_id')?:null;$parent=$this->request->integer('parent_act_id')?:null;$relation=(string)$this->request->input('relation_type','amends');
        if($parent&&!in_array($relation,['amends','replaces','revokes','implements'],true))throw new HttpException(422,'Неважечка врска.');
        $this->db->transaction(function()use($case,$number,$type,$date,$effective,$description,$geo,$attachment,$parent,$relation){
            if($attachment&&!$this->db->scalar("SELECT id FROM attachments WITH(UPDLOCK,HOLDLOCK) WHERE id=:id AND entity_type='AdministrativeCase' AND entity_id=:c AND scan_status='clean'",['id'=>$attachment,'c'=>$case]))throw new HttpException(422,'Изберете скениран документ од овој предмет.');
            if($this->db->scalar('SELECT TOP 1 1 FROM administrative_acts WHERE act_number=:number',['number'=>$number]))throw new HttpException(422,'Веќе постои акт со овој број.');
            if($parent&&!$this->db->scalar('SELECT id FROM administrative_acts WHERE id=:id AND case_id=:c',['id'=>$parent,'c'=>$case]))throw new HttpException(422,'Матичниот акт мора да припаѓа на овој предмет.');
            $id=$this->db->insertId("INSERT INTO administrative_acts(case_id,act_number,act_type,act_date,effective_date,status,description,attachment_id,created_by,created_at,latitude,longitude,google_maps_url) VALUES(:case,:number,:type,:date,:effective,'issued',:description,:attachment,:user,SYSUTCDATETIME(),:lat,:lng,:maps)",['case'=>$case,'number'=>$number,'type'=>$type,'date'=>$date,'effective'=>$effective,'description'=>$description,'attachment'=>$attachment,'user'=>$this->userId(),'lat'=>$geo['latitude'],'lng'=>$geo['longitude'],'maps'=>$geo['google_maps_url']]);
            if($parent)$this->db->execute('INSERT INTO administrative_act_relations(parent_act_id,child_act_id,relation_type,created_by,created_at) VALUES(:parent,:child,:type,:u,SYSUTCDATETIME())',['parent'=>$parent,'child'=>$id,'type'=>$relation,'u'=>$this->userId()]);
            $this->timeline($case,'ACT_ISSUED','Акт '.$number.' е евидентиран.',$id);$this->audit->record($this->userId(),'act.issued','AdministrativeAct',$id,[],['case'=>$case,'number'=>$number]+$geo);
        });$this->redirect('/cases/'.$case,'Актот е евидентиран и не може да се менува.');
    }

    private function timeline(int $case,string $type,string $description,?int $act=null,?int $attachment=null): void
    {
        $this->db->execute('INSERT INTO case_actions(case_id,action_type,description,action_date,performed_by,related_act_id,related_attachment_id) VALUES(:c,:t,:d,SYSUTCDATETIME(),:u,:a,:file)',['c'=>$case,'t'=>$type,'d'=>$description,'u'=>$this->userId(),'a'=>$act,'file'=>$attachment]);
    }

    public function decisionLocation(string $type,int $id): never
    {
        $table=$type==='workflow'?'workflow_instances':'budget_change_requests';
        $row=$this->db->one("SELECT * FROM {$table} WHERE id=:id",['id'=>$id]);if(!$row)throw new HttpException(404,'Одлуката не е пронајдена.');
        if($type==='workflow'){
            if(!$this->scope->can($this->userId(),'view','workflow')||!$this->scope->allowsDirectorate($this->userId(),(int)$row['directorate_id']))throw new HttpException(403,'Немате пристап.');
        }else $this->access()->entity('App\\Models\\BudgetChangeRequest',$id);
        if($this->request->method==='POST'){
            if(!$this->scope->can($this->userId(),'create','budget_change'))throw new HttpException(403,'Немате право на измена.');
            $geo=GeoReference::parse($_POST);
            $this->db->transaction(function()use($table,$row,$geo,$id){$this->db->execute("UPDATE {$table} SET latitude=:latitude,longitude=:longitude,google_maps_url=:google_maps_url,updated_at=SYSUTCDATETIME() WHERE id=:id",$geo+['id'=>$id]);$this->audit->record($this->userId(),'decision.location_updated',$table,$id,array_intersect_key($row,$geo),$geo);});
            $this->redirect('/decisions/'.$type.'/'.$id.'/location','Локацијата е зачувана.');
        }
        $this->page('Локација на одлуката #'.$id,'<section class="card">'.$this->form('/decisions/'.$type.'/'.$id.'/location',\EKabineti\Service\EvidenceUi::geo($row).'<button>Зачувај ја локацијата</button>').'</section>');
    }

    public function monitoring(): never
    {
        $projects=[];$projectIssues=0;$lateProjects=0;
        foreach($this->db->all('SELECT TOP 500 p.*,COALESCE((SELECT SUM(e.amount) FROM expenditures e JOIN budget_allocations a ON a.id=e.budget_allocation_id WHERE a.project_id=p.id),0) spent,COALESCE((SELECT SUM(current_amount) FROM budget_allocations WHERE project_id=p.id),0) allocated FROM projects p ORDER BY updated_at DESC') as$p){
            try{$this->access()->project((int)$p['id']);}catch(HttpException){continue;}
            $issues=[];
            if($p['latitude']===null)$issues[]='Без локација';
            if(!$p['manager_id'])$issues[]='Без одговорно лице';
            if($p['ends_at']&&substr((string)$p['ends_at'],0,10)<date('Y-m-d')&&!in_array($p['status'],['completed','cancelled'],true)){$issues[]='Изминат рок';$lateProjects++;}
            if(substr((string)$p['updated_at'],0,10)<date('Y-m-d',strtotime('-30 days')))$issues[]='Без ажурирање 30 дена';
            if($issues!==[])$projectIssues++;
            $p['_issues']=$issues;$projects[]=$p;
        }
        $cases=[];$overdueTasks=0;
        foreach($this->db->all("SELECT TOP 500 c.*,(SELECT COUNT(*) FROM case_tasks t WHERE t.case_id=c.id AND t.due_at<CONVERT(date,SYSUTCDATETIME()) AND t.status<>'completed') overdue FROM administrative_cases c ORDER BY updated_at DESC") as$c){
            try{$this->access()->caseFile((int)$c['id']);}catch(HttpException){continue;}
            $overdueTasks+=(int)$c['overdue'];$cases[]=$c;
        }
        $body='<div class="metrics"><div class="metric"><span>Проекти во следење</span><strong>'.count($projects).'</strong></div><div class="metric"><span>Проекти со недостатоци</span><strong>'.$projectIssues.'</strong></div><div class="metric"><span>Проекти надвор од рокот</span><strong>'.$lateProjects.'</strong></div><div class="metric"><span>Задачи по предмети надвор од рокот</span><strong>'.$overdueTasks.'</strong></div></div>';
        $body.='<section class="card"><h2>Напредок и квалитет на податоците</h2><div class="table-wrap"><table><thead><tr><th>Проект</th><th>Физички</th><th>Финансиски</th><th>Контроли</th></tr></thead><tbody>';
        $pagination=Pagination::fromRequest();
        foreach($pagination->slice($projects) as$p){$financial=(float)$p['allocated']>0?round((float)$p['spent']/(float)$p['allocated']*100,2):null;$body.='<tr><td><a href="/projects/'.$p['id'].'">'.View::e($p['code'].' · '.$p['name']).'</a></td><td>'.(int)$p['physical_progress'].'%</td><td>'.($financial===null?'Без распределба':$financial.'%').'</td><td>'.($p['_issues']===[]?'<span class="status-badge status-active">Во ред</span>':View::e(implode(' · ',$p['_issues']))).'</td></tr>';}
        $body.=($projects===[]?'<tr><td colspan="4">Нема проекти во вашиот опсег.</td></tr>':'').'</tbody></table></div>'.$pagination->nav().'</section><section class="card"><h2>Предмети и рокови</h2>';
        foreach($cases as$c)$body.='<p><a href="/cases/'.$c['id'].'">'.View::e($c['case_number'].' · '.$c['subject']).'</a> · Задачи надвор од рокот: '.(int)$c['overdue'].'</p>';
        $body.=($cases===[]?'<p class="muted">Нема предмети во вашиот опсег.</p>':'').'</section>';
        $this->page('Напредно следење',$body);
    }

    public function search(): never
    {
        $q=mb_substr(trim((string)$this->request->input('q','')),0,200);
        $body='<section class="card"><form method="get" action="/search"><label>Пребарај во проекти, предмети, документи и акти<input name="q" value="'.View::e($q).'" maxlength="200" required></label><button>Пребарај</button></form></section>';
        if($q==='')$this->page('Глобално пребарување',$body.'<section class="card"><p class="muted">Внесете поим за да започнете со пребарувањето. Резултатите се ограничени според вашиот пристап.</p></section>');
        $like='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$q).'%';$total=0;
        $body.='<section class="card"><h2>Проекти</h2>';$found=0;
        foreach($this->db->all('SELECT TOP 200 id,code,name,status FROM projects WHERE code LIKE :term OR name LIKE :term2 OR objective LIKE :term3 OR location_name LIKE :term4 OR address LIKE :term5 ORDER BY updated_at DESC',['term'=>$like,'term2'=>$like,'term3'=>$like,'term4'=>$like,'term5'=>$like]) as$p){try{$this->access()->project((int)$p['id']);}catch(HttpException){continue;}$body.='<p><a href="/projects/'.$p['id'].'">'.View::e($p['code'].' · '.$p['name']).'</a> · '.View::e($p['status']).'</p>';$found++;$total++;}
        $body.=($found===0?'<p class="muted">Не е пронајден ниеден проект.</p>':'').'</section><section class="card"><h2>Предмети</h2>';$found=0;
        foreach($this->db->all('SELECT TOP 200 id,case_number,subject,status FROM administrative_cases WHERE case_number LIKE :term OR subject LIKE :term2 OR description LIKE :term3 ORDER BY updated_at DESC',['term'=>$like,'term2'=>$like,'term3'=>$like]) as$c){try{$this->access()->caseFile((int)$c['id']);}catch(HttpException){continue;}$body.='<p><a href="/cases/'.$c['id'].'">'.View::e($c['case_number'].' · '.$c['subject']).'</a> · '.View::e($c['status']).'</p>';$found++;$total++;}
        $body.=($found===0?'<p class="muted">Не е пронајден ниеден предмет.</p>':'').'</section><section class="card"><h2>Документи</h2>';$found=0;
        foreach($this->db->all('SELECT TOP 200 id,entity_type,entity_id,title,original_name,scan_status FROM attachments WHERE title LIKE :term OR original_name LIKE :term2 ORDER BY id DESC',['term'=>$like,'term2'=>$like]) as$a){try{$this->access()->entity($a['entity_type'],(int)$a['entity_id']);}catch(HttpException){continue;}$label=View::e($a['title']?:$a['original_name']);$body.='<p>'.($a['scan_status']==='clean'?'<a href="/attachments/'.$a['id'].'">'.$label.'</a>':$label).' · '.View::e($a['scan_status']).'</p>';$found++;$total++;}
        $body.=($found===0?'<p class="muted">Не е пронајден ниеден документ.</p>':'').'</section><section class="card"><h2>Акти</h2>';$found=0;
        foreach($this->db->all('SELECT TOP 200 id,case_id,act_number,act_type,description FROM administrative_acts WHERE act_number LIKE :term OR act_type LIKE :term2 OR description LIKE :term3 ORDER BY id DESC',['term'=>$like,'term2'=>$like,'term3'=>$like]) as$a){try{$this->access()->caseFile((int)$a['case_id']);}catch(HttpException){continue;}$body.='<p><a href="/cases/'.$a['case_id'].'">'.View::e('Акт '.$a['act_number'].' · '.$a['act_type']).'</a></p>';$found++;$total++;}
        $body.=($found===0?'<p class="muted">Не е пронајден ниеден акт.</p>':'').'</section>';
        if($total===0)$body='<div class="alert" role="status">Не е пронајден ниеден резултат за „'.View::e($q).'”.</div>'.$body;
        $this->page('Глобално пребарување',$body);
    }
}
