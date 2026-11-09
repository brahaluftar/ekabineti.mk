<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

/**
 * Policy areas the executive works in at the same time. The area of a project is its own (projects.policy_area_id)
 * or its directorate's (directorates.policy_area_id); cases and risks follow their project or directorate. Area 0 is
 * "Pa fushë" (not assigned yet). A piece of work counts in a period when its dates overlap the period.
 */
final class ExecutiveAreas
{
    public const NONE = 'Без определена област';

    public function __construct(private readonly Database $db) {}

    /** Areas by id (active first, then inactive), with 0 for "not assigned". */
    public function areas(bool $activeOnly = false): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id,code,name,active,sort_order FROM policy_areas'.($activeOnly ? ' WHERE active=1' : '').' ORDER BY active DESC,sort_order,name') as $a) $out[(int) $a['id']] = $a;
        return $out;
    }

    /**
     * Per area for the period: directorates involved, active projects, average progress, open and late tasks,
     * open critical risks, open cases, open agenda items and the budget allocated to the projects.
     */
    public function overview(string $from, string $to): array
    {
        $today = date('Y-m-d');
        $year = (int) substr($to, 0, 4);
        $rows = [];
        $row = static function (array &$rows, int $area): void { $rows[$area] ??= ['area_id'=>$area, 'directorates'=>[], 'projects'=>0, 'progress'=>null, 'tasks_open'=>0, 'tasks_late'=>0, 'risks_critical'=>0, 'cases_open'=>0, 'agenda_open'=>0, 'budget'=>0.0]; };
        $active = "p.status='active' AND (p.starts_at IS NULL OR p.starts_at<=:to) AND (p.ends_at IS NULL OR p.ends_at>=:from)";

        foreach ($this->db->all("SELECT COALESCE(p.policy_area_id,d.policy_area_id,0) area,COUNT(*) projects,AVG(CAST(p.progress AS float)) progress FROM projects p JOIN directorates d ON d.id=p.directorate_id WHERE {$active} GROUP BY COALESCE(p.policy_area_id,d.policy_area_id,0)", ['from'=>$from, 'to'=>$to]) as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['projects'] = (int) $r['projects']; $rows[(int) $r['area']]['progress'] = round((float) $r['progress'], 1);
        }
        foreach ($this->db->all("SELECT COALESCE(p.policy_area_id,d.policy_area_id,0) area,SUM(CASE WHEN t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) open_tasks,SUM(CASE WHEN t.status NOT IN ('done','cancelled') AND t.due_at<:today THEN 1 ELSE 0 END) late_tasks FROM project_tasks t JOIN projects p ON p.id=t.project_id JOIN directorates d ON d.id=p.directorate_id WHERE {$active} AND (t.starts_at IS NULL OR t.starts_at<=:to2) AND (t.due_at IS NULL OR t.due_at>=:from2 OR t.status NOT IN ('done','cancelled')) GROUP BY COALESCE(p.policy_area_id,d.policy_area_id,0)", ['from'=>$from, 'to'=>$to, 'from2'=>$from, 'to2'=>$to, 'today'=>$today]) as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['tasks_open'] = (int) $r['open_tasks']; $rows[(int) $r['area']]['tasks_late'] = (int) $r['late_tasks'];
        }
        foreach ($this->db->all("SELECT COALESCE(p.policy_area_id,pd.policy_area_id,d.policy_area_id,0) area,COUNT(*) n FROM risks r JOIN directorates d ON d.id=r.directorate_id LEFT JOIN projects p ON p.id=r.project_id LEFT JOIN directorates pd ON pd.id=p.directorate_id WHERE COALESCE(r.residual_score,r.inherent_score)>=15 AND r.status NOT IN ('closed','mitigated') GROUP BY COALESCE(p.policy_area_id,pd.policy_area_id,d.policy_area_id,0)") as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['risks_critical'] = (int) $r['n'];
        }
        foreach ($this->db->all('SELECT COALESCE(d.policy_area_id,0) area,COUNT(*) n FROM administrative_cases c JOIN directorates d ON d.id=c.originating_directorate_id WHERE c.closed_at IS NULL AND (c.opened_at IS NULL OR c.opened_at<=:to) GROUP BY COALESCE(d.policy_area_id,0)', ['to'=>$to]) as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['cases_open'] = (int) $r['n'];
        }
        foreach ($this->db->all("SELECT COALESCE(p.policy_area_id,d.policy_area_id,0) area,COUNT(*) n FROM mayor_agenda_items m JOIN directorates d ON d.id=m.directorate_id LEFT JOIN projects p ON p.id=m.project_id WHERE m.status='open' GROUP BY COALESCE(p.policy_area_id,d.policy_area_id,0)") as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['agenda_open'] = (int) $r['n'];
        }
        foreach ($this->db->all("SELECT COALESCE(p.policy_area_id,d.policy_area_id,0) area,SUM(ba.current_amount) amount FROM budget_allocations ba JOIN projects p ON p.id=ba.project_id JOIN directorates d ON d.id=p.directorate_id WHERE ba.fiscal_year=:year AND {$active} GROUP BY COALESCE(p.policy_area_id,d.policy_area_id,0)", ['year'=>$year, 'from'=>$from, 'to'=>$to]) as $r) {
            $row($rows, (int) $r['area']); $rows[(int) $r['area']]['budget'] = (float) $r['amount'];
        }
        // Directorates working in each area: through their active projects, open cases or open critical risks.
        $dirs = $this->db->all("SELECT DISTINCT x.area,x.code FROM (
                SELECT COALESCE(p.policy_area_id,d.policy_area_id,0) area,d.code FROM projects p JOIN directorates d ON d.id=p.directorate_id WHERE {$active}
                UNION SELECT COALESCE(d.policy_area_id,0) area,d.code FROM administrative_cases c JOIN directorates d ON d.id=c.originating_directorate_id WHERE c.closed_at IS NULL AND (c.opened_at IS NULL OR c.opened_at<=:to2)
                UNION SELECT COALESCE(p.policy_area_id,pd.policy_area_id,d.policy_area_id,0) area,d.code FROM risks r JOIN directorates d ON d.id=r.directorate_id LEFT JOIN projects p ON p.id=r.project_id LEFT JOIN directorates pd ON pd.id=p.directorate_id WHERE COALESCE(r.residual_score,r.inherent_score)>=15 AND r.status NOT IN ('closed','mitigated')
            ) x ORDER BY x.code", ['from'=>$from, 'to'=>$to, 'to2'=>$to]);
        foreach ($dirs as $d) { $row($rows, (int) $d['area']); $rows[(int) $d['area']]['directorates'][] = (string) $d['code']; }

        $areas = $this->areas();
        foreach ($rows as $id => &$r) $r['name'] = $id === 0 ? self::NONE : (string) ($areas[$id]['name'] ?? self::NONE);
        unset($r);
        $order = array_flip(array_keys($areas));
        uasort($rows, static fn(array $a, array $b): int => [count($b['directorates']), $b['projects'], $order[$a['area_id']] ?? 999] <=> [count($a['directorates']), $a['projects'], $order[$b['area_id']] ?? 999]);
        return array_values($rows);
    }

    /** Projects of an area active in the period, with their open / late tasks and open critical risks. */
    public function areaProjects(int $areaId, string $from, string $to): array
    {
        $today = date('Y-m-d');
        return $this->db->all("SELECT p.id,p.code,p.name,p.progress,p.starts_at,p.ends_at,p.location_name,p.policy_area_id,d.code directorate_code,d.name directorate_name,
                (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id AND t.status NOT IN ('done','cancelled')) tasks_open,
                (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id AND t.status NOT IN ('done','cancelled') AND t.due_at<:today) tasks_late,
                (SELECT COUNT(*) FROM risks r WHERE r.project_id=p.id AND COALESCE(r.residual_score,r.inherent_score)>=15 AND r.status NOT IN ('closed','mitigated')) risks_critical
            FROM projects p JOIN directorates d ON d.id=p.directorate_id
            WHERE p.status='active' AND (p.starts_at IS NULL OR p.starts_at<=:to) AND (p.ends_at IS NULL OR p.ends_at>=:from) AND COALESCE(p.policy_area_id,d.policy_area_id,0)=:area
            ORDER BY d.code,p.starts_at,p.code", ['area'=>$areaId, 'from'=>$from, 'to'=>$to, 'today'=>$today]);
    }

    /** Open cases of an area (number, type, status: the subject is left out). */
    public function areaCases(int $areaId, string $to): array
    {
        return $this->db->all('SELECT c.id,c.case_number,c.case_type,c.status,c.priority,c.opened_at,c.is_legal,d.code directorate_code FROM administrative_cases c JOIN directorates d ON d.id=c.originating_directorate_id WHERE c.closed_at IS NULL AND (c.opened_at IS NULL OR c.opened_at<=:to) AND COALESCE(d.policy_area_id,0)=:area ORDER BY c.opened_at DESC', ['area'=>$areaId, 'to'=>$to]);
    }

    /**
     * Places where projects of different directorates run in the same period (same location name, case and spaces
     * ignored): coordination is needed (e.g. a road and a water network in the same street).
     */
    public function sharedLocations(string $from, string $to): array
    {
        $rows = $this->db->all("SELECT p.id,p.code,p.name,p.location_name,p.starts_at,p.ends_at,d.code directorate_code FROM projects p JOIN directorates d ON d.id=p.directorate_id WHERE p.status='active' AND p.location_name IS NOT NULL AND (p.starts_at IS NULL OR p.starts_at<=:to) AND (p.ends_at IS NULL OR p.ends_at>=:from) ORDER BY p.location_name,d.code", ['from'=>$from, 'to'=>$to]);
        $groups = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $r['location_name'])));
            if ($key !== '') $groups[$key][] = $r;
        }
        return array_values(array_filter($groups, static fn(array $g): bool => count(array_unique(array_column($g, 'directorate_code'))) > 1));
    }

    // --- Administration ---------------------------------------------------------------------------------------------

    public function createArea(string $name, string $sort = ''): int
    {
        $name = $this->name($name);
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM policy_areas WHERE name=:name', ['name'=>$name]) > 0) throw new \InvalidArgumentException('Веќе постои област со ова име.');
        $codes = array_flip(array_column($this->db->all("SELECT code FROM policy_areas WHERE code LIKE 'area_%'"), 'code'));
        for ($i = 1; isset($codes['area_'.$i]); $i++);
        $order = $sort !== '' && ctype_digit($sort) ? (int) $sort : (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+10 FROM policy_areas');
        return $this->db->insertId('INSERT INTO policy_areas(code,name,active,sort_order,created_at) VALUES(:code,:name,1,:sort,SYSUTCDATETIME())', ['code'=>'area_'.$i, 'name'=>$name, 'sort'=>$order]);
    }

    public function updateArea(int $id, string $name, string $sort, bool $active): void
    {
        $name = $this->name($name);
        if (!$this->db->scalar('SELECT COUNT(*) FROM policy_areas WHERE id=:id', ['id'=>$id])) throw new \InvalidArgumentException('Областа не е пронајдена.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM policy_areas WHERE name=:name AND id<>:id', ['name'=>$name, 'id'=>$id]) > 0) throw new \InvalidArgumentException('Веќе постои област со ова име.');
        if ($sort !== '' && (!ctype_digit($sort) || (int) $sort > 100000)) throw new \InvalidArgumentException('Редоследот мора да биде број од 0 до 100000.');
        $this->db->execute('UPDATE policy_areas SET name=:name,sort_order=COALESCE(:sort,sort_order),active=:active WHERE id=:id', ['name'=>$name, 'sort'=>$sort === '' ? null : (int) $sort, 'active'=>$active ? 1 : 0, 'id'=>$id]);
    }

    /** Area of a directorate (default of its work) or of a project (null = follow its directorate). */
    public function assign(string $table, int $id, ?int $areaId): void
    {
        if (!in_array($table, ['directorates', 'projects'], true)) throw new \LogicException('Unsafe table.');
        if ($areaId !== null && !$this->db->scalar('SELECT COUNT(*) FROM policy_areas WHERE id=:id AND active=1', ['id'=>$areaId])) throw new \InvalidArgumentException('Областа не е активна.');
        if (!$this->db->scalar("SELECT COUNT(*) FROM {$table} WHERE id=:id", ['id'=>$id])) throw new \InvalidArgumentException('Записот не е пронајден.');
        $this->db->execute("UPDATE {$table} SET policy_area_id=:area WHERE id=:id", ['area'=>$areaId, 'id'=>$id]);
    }

    private function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) throw new \InvalidArgumentException('Називот на областа мора да има од 3 до 150 знаци.');
        return $name;
    }
}
