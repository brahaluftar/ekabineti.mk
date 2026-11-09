<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $c):void{if(str_starts_with($c,'EKabineti\\'))require APP_ROOT.'/app/'.str_replace('\\','/',substr($c,10)).'.php';});
$config=require __DIR__.'/local-config.php';
$db=new EKabineti\Core\Database($config['database']);
if(!str_contains(strtolower($db->scalar('SELECT DB_NAME()')),'test'))throw new RuntimeException('Test database required.');
$scope=new EKabineti\Security\Scope($db);$n=0;
$check=static function(bool $ok,string $msg)use(&$n):void{if(!$ok)throw new RuntimeException($msg);$n++;};
$deny=static function(callable $f)use($check):void{try{$f();}catch(EKabineti\Core\HttpException $e){$check(in_array($e->status,[403,422],true),'Expected denial');return;}throw new RuntimeException('Missing authorization denial');};
$geo=EKabineti\Service\GeoReference::parse(['google_maps_url'=>'https://www.google.com/maps/search/?api=1&query=42.6629,21.1655']);
$check($geo['latitude']==='42.6629000'&&$geo['longitude']==='21.1655000','Google Maps coordinate extraction');
$check(EKabineti\Service\GeoReference::parse([])['latitude']===null,'Optional empty location');
$deny(fn()=>EKabineti\Service\GeoReference::parse(['latitude'=>'42']));
$deny(fn()=>EKabineti\Service\GeoReference::parse(['latitude'=>'91','longitude'=>'21']));
$deny(fn()=>EKabineti\Service\GeoReference::parse(['google_maps_url'=>'javascript:alert(1)']));
$deny(fn()=>EKabineti\Service\GeoReference::parse(['google_maps_url'=>'https://www.google.com.evil.test/maps']));
$db->pdo()->beginTransaction();
try{
 $suffix=bin2hex(random_bytes(5));$admin=(int)$db->scalar("SELECT TOP 1 user_id FROM user_role_scopes s JOIN roles r ON r.id=s.role_id WHERE r.code='system_admin'");
 $d1=(int)$db->scalar('SELECT TOP 1 id FROM directorates ORDER BY id');$d2=(int)$db->scalar('SELECT TOP 1 id FROM directorates WHERE id<>:d ORDER BY id',['d'=>$d1]);
 $makeUser=static function(string $role,int $dir)use($db,$suffix):int{$name=$role.$suffix;$u=$db->insertId("INSERT INTO users(name,username,email,password,status,locale,created_at,updated_at) VALUES(:name,:username,:email,'unused','active','mk',SYSUTCDATETIME(),SYSUTCDATETIME())",['name'=>$name,'username'=>$name,'email'=>$name.'@example.invalid']);$db->execute('INSERT INTO user_role_scopes(user_id,role_id,directorate_id,created_at,updated_at) VALUES(:u,(SELECT id FROM roles WHERE code=:role),:d,SYSUTCDATETIME(),SYSUTCDATETIME())',['u'=>$u,'role'=>$role,'d'=>$dir]);return $u;};
 $pr=$makeUser('public_relations',$d1);$director=$makeUser('director',$d2);$mayor=$makeUser('mayor',$d1);
 $project=$db->insertId("INSERT INTO projects(directorate_id,code,name,manager_id,status,has_budget_impact,progress,created_at,updated_at) VALUES(:d,:code,'Evidence probe',:manager,'draft',0,0,SYSUTCDATETIME(),SYSUTCDATETIME())",['d'=>$d1,'code'=>'EVID'.$suffix,'manager'=>$admin]);
 $task=$db->insertId("INSERT INTO project_tasks(project_id,title,status,progress,weight_percent,created_at,updated_at) VALUES(:p,'Private task','not_started',0,100,SYSUTCDATETIME(),SYSUTCDATETIME())",['p'=>$project]);
 $prAccess=new EKabineti\Service\EvidenceAccess($db,$scope,$pr);$dirAccess=new EKabineti\Service\EvidenceAccess($db,$scope,$director);
 $deny(fn()=>$prAccess->project($project));$deny(fn()=>$dirAccess->project($project));
 $check($scope->directorateIds($pr)===[],'PR has no blanket directorate access');
 $db->execute("INSERT INTO task_user_access(task_id,user_id,access_level,granted_by,created_at) VALUES(:t,:u,'viewer',:a,SYSUTCDATETIME())",['t'=>$task,'u'=>$pr,'a'=>$admin]);
 $check((int)$prAccess->task($task)['id']===$task,'Task-only grant permits task');$deny(fn()=>$prAccess->project($project));$deny(fn()=>$prAccess->task($task,true));
 $db->execute("UPDATE task_user_access SET access_level='editor' WHERE task_id=:t",['t'=>$task]);$check((int)$prAccess->task($task,true)['id']===$task,'Task editor allowed');
 $db->execute("INSERT INTO project_user_access(project_id,user_id,access_level,granted_by,created_at) VALUES(:p,:u,'viewer',:a,SYSUTCDATETIME())",['p'=>$project,'u'=>$director,'a'=>$admin]);
 $check((int)$dirAccess->project($project)['id']===$project,'Cross-directorate explicit project view');$deny(fn()=>$dirAccess->project($project,true));
 $db->execute("UPDATE project_user_access SET access_level='editor' WHERE project_id=:p",['p'=>$project]);$check((int)$dirAccess->project($project,true)['id']===$project,'Project editor allowed');$deny(fn()=>$dirAccess->project($project,true,true));
 $case=$db->insertId("INSERT INTO administrative_cases(case_number,case_type,subject,originating_directorate_id,status,priority,opened_at,created_by,created_at,updated_at) VALUES(:n,'general','Evidence case',:d,'open','normal',CONVERT(date,SYSUTCDATETIME()),:u,SYSUTCDATETIME(),SYSUTCDATETIME())",['n'=>'CASE'.$suffix,'d'=>$d1,'u'=>$admin]);
 $mayorAccess=new EKabineti\Service\EvidenceAccess($db,$scope,$mayor);$check((int)$mayorAccess->caseFile($case)['id']===$case,'Mayor can read a case');$deny(fn()=>$mayorAccess->caseFile($case,true));
 $agendaCase=$db->insertId("INSERT INTO mayor_agenda_items(directorate_id,item_type,project_id,risk_id,case_id,priority,status,selected_by) VALUES(:d,'case',NULL,NULL,:case,'normal','open',:u)",['d'=>$d1,'case'=>$case,'u'=>$mayor]);$check((int)$db->scalar("SELECT case_id FROM mayor_agenda_items WHERE id=:id AND item_type='case'",['id'=>$agendaCase])===$case,'Administrative case can be placed on Mayor agenda');
 $deny(fn()=>$dirAccess->caseFile($case));
 $db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,assigned_by,assigned_at) VALUES(:c,:d,'ACTION_REQUIRED',:u,SYSUTCDATETIME())",['c'=>$case,'d'=>$d2,'u'=>$admin]);
 $check((int)$dirAccess->caseFile($case,true)['id']===$case,'Shared directorate can work on case');$deny(fn()=>$dirAccess->caseFile($case,true,true));
 $db->execute('UPDATE user_role_scopes SET valid_to=DATEADD(day,-1,SYSUTCDATETIME()) WHERE user_id=:u',['u'=>$director]);$deny(fn()=>$dirAccess->caseFile($case));
 $db->execute('INSERT INTO case_user_access(case_id,user_id,granted_by) VALUES(:c,:u,:a)',['c'=>$case,'u'=>$pr,'a'=>$admin]);$check((int)$prAccess->caseFile($case)['id']===$case,'Explicit case user grant');
 $deny(fn()=>$prAccess->leadership());$deny(fn()=>(new EKabineti\Service\EvidenceAccess($db,$scope,$admin))->leadership());
 $act=$db->insertId("INSERT INTO administrative_acts(case_id,act_number,act_type,act_date,status,created_by,created_at,latitude,longitude,google_maps_url) VALUES(:c,:n,'decision',CONVERT(date,SYSUTCDATETIME()),'issued',:u,SYSUTCDATETIME(),:lat,:lng,:url)",['c'=>$case,'n'=>'ACT'.$suffix,'u'=>$admin,'lat'=>$geo['latitude'],'lng'=>$geo['longitude'],'url'=>$geo['google_maps_url']]);
 $check((string)$db->scalar('SELECT latitude FROM administrative_acts WHERE id=:id',['id'=>$act])==='42.6629000','Act georeference persisted');
 try{$db->execute("UPDATE administrative_acts SET description='tampered' WHERE id=:id",['id'=>$act]);throw new RuntimeException('Immutable act was updated');}catch(PDOException $e){$check(str_contains($e->getMessage(),'51001')||str_contains($e->getMessage(),'Управни акти'),'SQL rejects act modification');}
}finally{if($db->pdo()->inTransaction())$db->pdo()->rollBack();}
echo "PASS: {$n} evidence checks; fixtures rolled back.\n";
