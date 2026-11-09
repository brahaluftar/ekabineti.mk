<?php

declare(strict_types=1);

namespace EKabineti\Security;

use EKabineti\Core\Database;

final class Scope
{
    private const GLOBAL_ROLES = ['mayor', 'mayor_admin', 'deputy_mayor', 'finance', 'procurement_officer', 'procurement_manager', 'system_admin'];
    private const FINANCIAL_ROLES = ['mayor', 'deputy_mayor', 'director', 'directors_assistant', 'budget_officer', 'finance', 'system_admin'];
    private const LEGAL_ROLES = ['legal_office','legal_director','senior_legal_officer','legal_officer','legal_assistant'];

    /** @var array<int,array> Active role scopes per user, memoised for the lifetime of the request. */
    private array $activeCache = [];
    private ?int $legalDirectorateId = null;

    public function __construct(private readonly Database $db) {}

    public function roles(int $userId): array
    {
        $roles = array_column($this->active($userId), 'role_code');
        if (in_array('directors_assistant', $roles, true) && !in_array('director', $roles, true)) $roles[] = 'director';
        return $roles;
    }

    public function isSystemAdmin(int $userId): bool
    {
        return in_array('system_admin', $this->roles($userId), true);
    }

    public function isMayor(int $userId): bool
    {
        return in_array('mayor', $this->roles($userId), true);
    }

    public function canManageDirectorates(int $userId): bool
    {
        return array_intersect(['system_admin', 'mayor'], $this->roles($userId)) !== [];
    }

    public function canAccessMayorDashboard(int $userId): bool
    {
        return in_array('mayor', $this->roles($userId), true);
    }

    public function canAccessProcurementList(int $userId): bool
    {
        return array_intersect(['system_admin','mayor','deputy_mayor','director','directors_assistant','finance','procurement_officer','procurement_manager'], $this->roles($userId)) !== [] || $this->canWorkLegalOffice($userId);
    }

    public function canAccessExecutedCases(int $userId): bool
    {
        return array_intersect(['system_admin','finance','procurement_officer','procurement_manager'], $this->roles($userId)) !== [] || $this->canWorkLegalOffice($userId);
    }

    public function canImportExecutedCases(int $userId): bool
    {
        return in_array('finance', $this->roles($userId), true);
    }

    /** Të hyrat: Finance, the Mayor's office and the system administrator see the payments (they include personal data). */
    public function canAccessRevenues(int $userId): bool
    {
        return array_intersect(['system_admin','finance','mayor','mayor_admin','deputy_mayor','budget_officer'], $this->roles($userId)) !== [];
    }

    /** Only Finance (or a system administrator) imports the daily revenue report and edits the types of revenue. */
    public function canImportRevenues(int $userId): bool
    {
        return array_intersect(['system_admin','finance'], $this->roles($userId)) !== [];
    }

    /** FreeBalance covers the whole municipality, so only Finance (or a system administrator) imports it and maps its programs. */
    public function canImportBudgetExecution(int $userId): bool
    {
        return array_intersect(['system_admin','finance'], $this->roles($userId)) !== [];
    }

    /**
     * Linking a project to budget lines of another directorate or another category, and choosing the project of
     * budget-execution rows, is reserved to the Directorate of Finance (role finance or a role in directorate FIN) and
     * the system administrator.
     */
    public function canLinkAcrossBudget(int $userId): bool
    {
        if (array_intersect(['system_admin','finance'], $this->roles($userId)) !== []) return true;
        foreach ($this->active($userId) as $scope) {
            if ($scope['directorate_id'] !== null && strtoupper((string) $this->db->scalar('SELECT code FROM directorates WHERE id=:id', ['id'=>(int) $scope['directorate_id']])) === 'FIN') return true;
        }
        return false;
    }

    public function canManageExecutedCaseFinance(int $userId): bool
    {
        return array_intersect(['system_admin','finance'], $this->roles($userId)) !== [];
    }

    public function canManageExecutedCaseLegal(int $userId): bool
    {
        return $this->canWorkLegalOffice($userId);
    }

    public function canAccessLegalOffice(int $userId): bool
    {
        return $this->canWorkLegalOffice($userId) || array_intersect(['mayor','mayor_admin','deputy_mayor'], $this->roles($userId)) !== [];
    }

    public function canWorkLegalOffice(int $userId): bool
    {
        $legalDirectorate=$this->legalDirectorateId();
        if($legalDirectorate===0)return false;
        foreach($this->active($userId)as$scope)if(in_array($scope['role_code'],array_merge(self::LEGAL_ROLES,['director','directors_assistant']),true)&&(int)($scope['directorate_id']??0)===$legalDirectorate)return true;
        return false;
    }

    public function canManageLegalOffice(int $userId): bool
    {
        $legalDirectorate=$this->legalDirectorateId();
        if($legalDirectorate===0)return false;
        foreach($this->active($userId)as$scope)if(in_array($scope['role_code'],['legal_director','director'],true)&&(int)($scope['directorate_id']??0)===$legalDirectorate)return true;
        return false;
    }

    public function legalRoleCodes(): array { return self::LEGAL_ROLES; }

    public function canAccessAiReporting(int $userId): bool
    {
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['mayor', 'mayor_admin', 'director'], true)) return true;
        }
        return false;
    }

    public function canAccessCitizenServices(int $userId): bool
    {
        return array_intersect(['system_admin', 'mayor', 'mayor_admin', 'deputy_mayor', 'director', 'directors_assistant', 'reporter'], $this->roles($userId)) !== []
            || $this->citizenSectorIds($userId) !== [];
    }

    /** Sectors (of the service catalog) the user is assigned to report. */
    public function citizenSectorIds(int $userId): array
    {
        try { return array_map('intval', array_column($this->db->all('SELECT sector_id FROM citizen_service_sector_reporters WHERE user_id=:user AND active=1', ['user'=>$userId]), 'sector_id')); }
        catch (\Throwable) { return []; }
    }

    /** Weekly report of a sector: its assigned officers, or the director / assistant of the directorate. Not the read-only roles. */
    public function canReportCitizenSector(int $userId, int $sectorId, int $directorateId): bool
    {
        if ($this->canViewCitizenServicesReportOnly($userId)) return false;
        if (in_array($sectorId, $this->citizenSectorIds($userId), true)) return true;
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['director', 'directors_assistant'], true) && (int) ($scope['directorate_id'] ?? 0) === $directorateId) return true;
        }
        return false;
    }

    /** Tiers and reporters of a directorate's services: the director / assistant, or a system administrator. */
    public function canManageCitizenSectors(int $userId, int $directorateId): bool
    {
        if ($this->isSystemAdmin($userId)) return true;
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['director', 'directors_assistant'], true) && (int) ($scope['directorate_id'] ?? 0) === $directorateId) return true;
        }
        return false;
    }

    /** Directorates whose services and sectors the user administers: null = all (system administrators). */
    public function citizenManageDirectorateIds(int $userId): ?array
    {
        if ($this->isSystemAdmin($userId)) return null;
        $ids = [];
        foreach ($this->active($userId) as $scope) if (in_array($scope['role_code'], ['director', 'directors_assistant'], true) && $scope['directorate_id'] !== null) $ids[] = (int) $scope['directorate_id'];
        return array_values(array_unique($ids));
    }

    /** Directorates whose service reports the user sees: null = all (cabinet, administrators). */
    public function citizenReportDirectorateIds(int $userId): ?array
    {
        if ($this->canViewCitizenServicesReportOnly($userId)) return null;
        $ids = [];
        foreach ($this->active($userId) as $scope) if ($scope['directorate_id'] !== null) $ids[] = (int) $scope['directorate_id'];
        $sectors = $this->citizenSectorIds($userId);
        if ($sectors !== []) {
            $marks = []; $params = [];
            foreach ($sectors as $i => $id) { $marks[] = ':s'.$i; $params['s'.$i] = $id; }
            foreach ($this->db->all('SELECT DISTINCT directorate_id FROM citizen_service_sectors WHERE id IN ('.implode(',', $marks).')', $params) as $row) $ids[] = (int) $row['directorate_id'];
        }
        return array_values(array_unique($ids));
    }

    public function canViewCitizenServicesReportOnly(int $userId): bool
    {
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['system_admin', 'mayor', 'mayor_admin', 'deputy_mayor'], true)) return true;
        }
        return false;
    }

    public function canReportCitizenServices(int $userId, int $directorateId): bool
    {
        if ($this->canViewCitizenServicesReportOnly($userId)) return false;
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['director', 'directors_assistant', 'reporter'], true)
                && $scope['directorate_id'] !== null
                && (int) $scope['directorate_id'] === $directorateId) return true;
        }
        return false;
    }

    public function canManageCitizenServices(int $userId): bool
    {
        return false;
    }

    public function canAccessInspectionReporting(int $userId): bool
    {
        if (array_intersect(['system_admin','mayor','mayor_admin','deputy_mayor'], $this->roles($userId)) !== []) return true;
        $inspection=$this->db->one("SELECT TOP 1 id FROM directorates WHERE active=1 AND (UPPER(code) IN ('INS','DI') OR LOWER(name) LIKE N'%inspek%' OR LOWER(name) LIKE N'%инспек%') ORDER BY CASE WHEN UPPER(code)='INS' THEN 0 ELSE 1 END,id");
        if (!$inspection) return false;
        foreach ($this->active($userId) as $scope) if (in_array($scope['role_code'],['director','directors_assistant','reporter'],true) && (int)($scope['directorate_id']??0)===(int)$inspection['id']) return true;
        return false;
    }

    public function canSubmitInspectionReporting(int $userId,int $directorateId): bool
    {
        foreach ($this->active($userId) as $scope) if (in_array($scope['role_code'],['director','directors_assistant','reporter'],true) && (int)($scope['directorate_id']??0)===$directorateId) return true;
        return false;
    }

    /** Weekly briefing of the Mayor and confirming meeting decisions: the Mayor's cabinet and system administrators. */
    public function canAccessMayorBriefing(int $userId): bool
    {
        return array_intersect(['system_admin', 'mayor', 'mayor_admin', 'deputy_mayor'], $this->roles($userId)) !== [];
    }

    /** Directorates whose weekly briefing the user reads: null = all (cabinet, system administrators), else the director's. */
    public function directorateBriefingIds(int $userId): ?array
    {
        if ($this->canAccessMayorBriefing($userId)) return null;
        $ids = [];
        foreach ($this->active($userId) as $scope) if (in_array($scope['role_code'], ['director', 'directors_assistant'], true) && $scope['directorate_id'] !== null) $ids[] = (int) $scope['directorate_id'];
        return array_values(array_unique($ids));
    }

    public function canAccessMayorAgenda(int $userId): bool
    {
        $ids = $this->mayorAgendaDirectorateIds($userId);
        return $ids === null || $ids !== [];
    }

    public function canManageMayorAgenda(int $userId, int $directorateId): bool
    {
        $ids = $this->mayorAgendaDirectorateIds($userId);
        return $ids === null || in_array($directorateId, $ids, true);
    }

    public function mayorAgendaDirectorateIds(int $userId): ?array
    {
        $ids = [];
        foreach ($this->active($userId) as $scope) {
            if (in_array($scope['role_code'], ['system_admin', 'mayor', 'mayor_admin', 'deputy_mayor'], true)) return null;
            if ($scope['role_code'] === 'director' && $scope['directorate_id'] !== null) $ids[] = (int) $scope['directorate_id'];
        }
        return array_values(array_unique($ids));
    }

    public function directorateIds(int $userId): ?array
    {
        // Public Relations receives explicit project/task grants, not directorate-wide access.
        $scopes = array_values(array_filter($this->active($userId),static fn(array $s): bool => $s['role_code']!=='public_relations'));
        foreach ($scopes as $scope) {
            if (in_array($scope['role_code'], self::GLOBAL_ROLES, true)) return null;
        }
        return array_values(array_unique(array_map('intval', array_filter(array_column($scopes, 'directorate_id')))));
    }

    public function allowsDirectorate(int $userId, int $directorateId): bool
    {
        $ids = $this->directorateIds($userId);
        return $ids === null || in_array($directorateId, $ids, true);
    }

    public function allowsFinancial(int $userId, int $directorateId): bool
    {
        foreach ($this->active($userId) as $scope) {
            if (!in_array($scope['role_code'], self::FINANCIAL_ROLES, true)) continue;
            if (in_array($scope['role_code'], ['director', 'directors_assistant'], true)) {
                if ($scope['directorate_id'] !== null && (int) $scope['directorate_id'] === $directorateId) return true;
                continue;
            }
            if ($scope['directorate_id'] === null || (int) $scope['directorate_id'] === $directorateId) return true;
        }
        return false;
    }

    public function can(int $userId, string $action, string $resource): bool
    {
        if ($this->isSystemAdmin($userId)) return true;
        return (bool) $this->db->scalar(
            'SELECT TOP 1 1 FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id AND r.active=1 JOIN permission_role pr ON pr.role_id=urs.role_id JOIN permissions p ON p.id=pr.permission_id WHERE urs.user_id=:u AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) AND p.action=:a AND p.resource_type=:r',
            ['u' => $userId, 'a' => $action, 'r' => $resource]
        );
    }

    public function satisfiesWorkflowRole(int $userId, string $requiredRole): bool
    {
        $roles = $this->roles($userId);
        return in_array($requiredRole, $roles, true)
            || ($requiredRole === 'director' && in_array('directors_assistant', $roles, true));
    }

    public function sql(int $userId, string $column = 'directorate_id', string $prefix = 'scope'): array
    {
        $ids = $this->directorateIds($userId);
        if ($ids === null) return ['1=1', []];
        if ($ids === []) return ['1=0', []];
        $params = [];
        $marks = [];
        foreach ($ids as $i => $id) { $key = $prefix.$i; $marks[] = ':'.$key; $params[$key] = $id; }
        return ["{$column} IN (".implode(',', $marks).')', $params];
    }

    public function budgetLineSql(int $userId,string $lineAlias='bl',string $capability='report',string $prefix='budgetLine'): array
    {
        $column=$capability==='select'?'can_select':($capability==='report'?'can_report':throw new \InvalidArgumentException('Invalid budget-line capability.'));
        $ids=$capability==='select'?$this->financialDirectorateIds($userId):$this->directorateIds($userId);
        if($ids===null)return['1=1',[]];
        if($ids===[])return['1=0',[]];
        $params=[];$ownerMarks=[];$accessMarks=[];foreach($ids as$i=>$id){$ownerKey=$prefix.'Owner'.$i;$accessKey=$prefix.'Access'.$i;$ownerMarks[]=':'.$ownerKey;$accessMarks[]=':'.$accessKey;$params[$ownerKey]=$id;$params[$accessKey]=$id;}
        return["({$lineAlias}.directorate_id IN (".implode(',',$ownerMarks).") OR EXISTS(SELECT 1 FROM budget_line_directorate_access blda WHERE blda.budget_line_id={$lineAlias}.id AND blda.directorate_id IN (".implode(',',$accessMarks).") AND blda.{$column}=1))",$params];
    }

    public function canUseBudgetLine(int $userId,int $lineId,int $ownerDirectorateId,string $capability): bool
    {
        $ids=$capability==='select'?$this->financialDirectorateIds($userId):$this->directorateIds($userId);
        if($ids===null)return true;
        if(in_array($ownerDirectorateId,$ids,true))return true;
        if($ids===[])return false;
        $column=$capability==='select'?'can_select':($capability==='report'?'can_report':throw new \InvalidArgumentException('Invalid budget-line capability.'));
        $params=['line'=>$lineId];$marks=[];foreach($ids as$i=>$id){$key='budgetLineUse'.$i;$marks[]=':'.$key;$params[$key]=$id;}
        return(bool)$this->db->scalar("SELECT TOP 1 1 FROM budget_line_directorate_access WHERE budget_line_id=:line AND directorate_id IN (".implode(',',$marks).") AND {$column}=1",$params);
    }

    public function budgetAllocationSql(int$userId,string$lineAlias='bl',string$projectAlias='p',string$prefix='budgetAllocation'): array
    {
        $ids=$this->financialDirectorateIds($userId);if($ids===null)return['1=1',[]];if($ids===[])return['1=0',[]];
        $params=[];$ownerMarks=[];$projectMarks=[];foreach($ids as$i=>$id){$ownerKey=$prefix.'Owner'.$i;$projectKey=$prefix.'Project'.$i;$ownerMarks[]=':'.$ownerKey;$projectMarks[]=':'.$projectKey;$params[$ownerKey]=$id;$params[$projectKey]=$id;}
        return["({$lineAlias}.directorate_id IN (".implode(',',$ownerMarks).") OR ({$projectAlias}.directorate_id IN (".implode(',',$projectMarks).") AND EXISTS(SELECT 1 FROM budget_line_directorate_access blda WHERE blda.budget_line_id={$lineAlias}.id AND blda.directorate_id={$projectAlias}.directorate_id AND blda.can_select=1)))",$params];
    }

    public function budgetLineAllowsDirectorate(int $lineId,int $ownerDirectorateId,int $directorateId,string $capability): bool
    {
        if($ownerDirectorateId===$directorateId)return true;
        $column=$capability==='select'?'can_select':($capability==='report'?'can_report':throw new \InvalidArgumentException('Invalid budget-line capability.'));
        return(bool)$this->db->scalar("SELECT TOP 1 1 FROM budget_line_directorate_access WHERE budget_line_id=:line AND directorate_id=:directorate AND {$column}=1",['line'=>$lineId,'directorate'=>$directorateId]);
    }

    private function financialDirectorateIds(int $userId): ?array
    {
        $ids=[];foreach($this->active($userId)as$scope){
            if(!in_array($scope['role_code'],self::FINANCIAL_ROLES,true))continue;
            if($scope['directorate_id']===null&&!in_array($scope['role_code'],['director','directors_assistant'],true))return null;
            if($scope['directorate_id']!==null)$ids[]=(int)$scope['directorate_id'];
        }
        return array_values(array_unique($ids));
    }

    private function legalDirectorateId(): int
    {
        return $this->legalDirectorateId ??= (int) ($this->db->scalar("SELECT TOP 1 id FROM directorates WHERE code='PRAV' AND active=1") ?: 0);
    }

    private function active(int $userId): array
    {
        return $this->activeCache[$userId] ??= $this->db->all('SELECT urs.directorate_id, urs.program_id, urs.project_id, COALESCE(r.base_role_code,r.code) role_code, r.code own_role_code FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE urs.user_id=:u AND r.active=1 AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())', ['u' => $userId]);
    }
}
