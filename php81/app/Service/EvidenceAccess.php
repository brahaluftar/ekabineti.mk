<?php
declare(strict_types=1);
namespace EKabineti\Service;
use EKabineti\Core\Database;
use EKabineti\Core\HttpException;
use EKabineti\Security\Scope;

final class EvidenceAccess
{
    public function __construct(private Database $db, private Scope $scope, private int $user) {}

    public function project(int $id, bool $write=false, bool $manage=false): array
    {
        $p=$this->db->one('SELECT * FROM projects WHERE id=:id',['id'=>$id]);
        if (!$p) throw new HttpException(404,'Проектот не е пронајден.');
        $level=$this->db->scalar('SELECT access_level FROM project_user_access WHERE project_id=:p AND user_id=:u',['p'=>$id,'u'=>$this->user]);
        $native=$this->scope->allowsDirectorate($this->user,(int)$p['directorate_id']) && $this->scope->can($this->user,$write?'create':'view','project');
        if (!$native && !in_array($level,$manage?['manager']:($write?['editor','manager']:['viewer','editor','manager']),true)) throw new HttpException(403,'Немате пристап до проектот.');
        return $p;
    }

    public function task(int $id, bool $write=false): array
    {
        $t=$this->db->one('SELECT * FROM project_tasks WHERE id=:id',['id'=>$id]);
        if (!$t) throw new HttpException(404,'Задачата не е пронајдена.');
        try {$this->project((int)$t['project_id'],$write);return $t;} catch(HttpException $e) {if($e->status!==403)throw $e;}
        $level=$this->db->scalar('SELECT access_level FROM task_user_access WHERE task_id=:t AND user_id=:u',['t'=>$id,'u'=>$this->user]);
        if (!in_array($level,$write?['editor']:['viewer','editor'],true)) throw new HttpException(403,'Немате пристап до задачата.');
        return $t;
    }

    public function caseFile(int $id, bool $write=false, bool $manage=false): array
    {
        $c=$this->db->one('SELECT * FROM administrative_cases WHERE id=:id',['id'=>$id]);
        if(!$c)throw new HttpException(404,'Предметот не е пронајден.');
        $roles=$this->scope->roles($this->user);
        if($write&&in_array('mayor',$roles,true))throw new HttpException(403,'Улогата на Градоначалникот има само пристап за читање до предметите.');
        if((bool)($c['is_legal']??false)){
            if($manage&&!$this->scope->canManageLegalOffice($this->user))throw new HttpException(403,'Само Раководителот на Правната служба може да управува со определувањето.');
            if($write&&!$this->scope->canWorkLegalOffice($this->user))throw new HttpException(403,'Само Правната служба може да го менува правниот предмет.');
            if(!$write&&!$this->scope->canAccessLegalOffice($this->user))throw new HttpException(403,'Немате пристап до правниот предмет.');
            // Confidential files: Legal Office only. Restricted files: responsible, deputy and the Legal Office manager only.
            $confidentiality=(string)($c['confidentiality']??'internal');
            if($confidentiality==='confidential'&&!$this->scope->canWorkLegalOffice($this->user))throw new HttpException(403,'Досието е доверливо и достапно само за Правната служба.');
            if($confidentiality==='restricted'&&!in_array($this->user,[(int)($c['responsible_user_id']??0),(int)($c['deputy_user_id']??0)],true)&&!$this->scope->canManageLegalOffice($this->user))throw new HttpException(403,'Досието е ограничено на одговорното лице, заменикот и Раководителот на Правната служба.');
            return$c;
        }
        if(array_intersect($roles,['system_admin','mayor','mayor_admin']))return $c;
        $assigned=(bool)$this->db->scalar('SELECT TOP 1 1 FROM case_user_access WHERE case_id=:c AND user_id=:u',['c'=>$id,'u'=>$this->user]);
        $ids=$this->scope->directorateIds($this->user)??[];
        $linked=array_map('intval',array_column($this->db->all('SELECT directorate_id FROM case_directorates WHERE case_id=:c',['c'=>$id]),'directorate_id'));
        $native=in_array((int)$c['originating_directorate_id'],$ids,true)||array_intersect($ids,$linked)!==[];
        if($manage && (!in_array((int)$c['originating_directorate_id'],$ids,true)||!$this->scope->can($this->user,'create','case')))throw new HttpException(403,'Само надлежниот сектор може да го сподели предметот.');
        if(!$native&&!$assigned)throw new HttpException(403,'Немате пристап до предметот.');
        if($write&&!$assigned&&!$this->scope->can($this->user,'create','case'))throw new HttpException(403,'Немате право на измена.');
        return $c;
    }

    public function entity(string $type,int $id,bool $write=false): void
    {
        if($type==='App\\Models\\Project'){$this->project($id,$write);return;}
        if($type==='App\\Models\\ProjectTask'){$this->task($id,$write);return;}
        if($type==='AdministrativeCase'){$this->caseFile($id,$write);return;}
        if($type==='AdministrativeAct'){$a=$this->db->one('SELECT case_id FROM administrative_acts WHERE id=:id',['id'=>$id]);if(!$a)throw new HttpException(404,'Актот не е пронајден.');$this->caseFile((int)$a['case_id'],$write);return;}
        $table=match($type){'App\\Models\\Risk'=>'risks','App\\Models\\BudgetChangeRequest'=>'budget_change_requests',default=>throw new HttpException(422,'Непознат ентитет.')};
        $row=$table==='budget_change_requests'
            ? $this->db->one('SELECT bl.directorate_id FROM budget_change_requests c JOIN budget_allocations a ON a.id=c.source_allocation_id JOIN budget_lines bl ON bl.id=a.budget_line_id WHERE c.id=:id',['id'=>$id])
            : $this->db->one("SELECT directorate_id FROM {$table} WHERE id=:id",['id'=>$id]);
        if(!$row||!$this->scope->allowsDirectorate($this->user,(int)$row['directorate_id']))throw new HttpException(403,'Надвор од вашиот сектор.');
    }

    public function leadership(): void
    {
        if(!array_intersect(['director','mayor'],$this->scope->roles($this->user)) || in_array('directors_assistant',$this->scope->roles($this->user),true) && !in_array('mayor',$this->scope->roles($this->user),true))throw new HttpException(403,'Врската е дозволена само за раководителите на сектори и Градоначалникот.');
    }

    public function activeUser(int $id): void
    {
        if(!$this->db->scalar("SELECT id FROM users WHERE id=:id AND status='active'",['id'=>$id]))throw new HttpException(422,'Корисникот не е активен.');
    }
}
