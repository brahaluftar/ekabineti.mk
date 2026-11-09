<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

/**
 * Dynamic roles. A role created in the application is built on a system role (base_role_code): it behaves like that
 * role where the application checks roles by code, and its permissions are chosen one by one. System roles keep their
 * code; their permissions can be changed, except those of the system administrator, who always has every permission.
 */
final class RoleAdmin
{
    /** Albanian labels of the permissions (action:resource); other permissions show their stored description. */
    public const LABELS = [
        'view:project'=>'Прегледај проекти', 'create:project'=>'Создавај и уредувај проекти', 'create:project_report'=>'Известувај за напредокот на проектите', 'manage:project_share'=>'Споделувај проекти и задачи со корисници',
        'view:risk'=>'Прегледај ризици', 'create:risk'=>'Создавај и уредувај ризици',
        'view:budget'=>'Прегледај го буџетот', 'create:allocation'=>'Создавај и уредувај буџетски распределби', 'view:execution'=>'Прегледај извршување (обврски, расходи)', 'post:commitment'=>'Евидентирај обврски', 'post:expenditure'=>'Евидентирај расходи',
        'view:budget_change'=>'Прегледај ги буџетските промени', 'create:budget_change'=>'Барај буџетски промени', 'approve:budget_change'=>'Одобрувај буџетски промени',
        'view:workflow'=>'Прегледај текови на одобрување', 'decide:workflow'=>'Одлучувај во тековите на одобрување',
        'view:case'=>'Прегледај управни предмети', 'create:case'=>'Создавај и обработувај управни предмети',
        'view:attachment'=>'Прегледај документи', 'create:attachment'=>'Прикачувај документи', 'view:document_link'=>'Отворај документи преку врска',
        'view:report'=>'Прегледај извештаи', 'export:report'=>'Извезувај извештаи', 'view:import'=>'Прегледај увози', 'create:import'=>'Увезувај податоци',
        'view:legal_office'=>'Прегледај го регистарот на Правната служба', 'create:legal_office'=>'Работи со предметите на Правната служба', 'manage:legal_office'=>'Администрирај ја Правната служба', 'export:legal_office'=>'Извезувај од Правната служба',
        'manage:user'=>'Администрирај корисници и пристапи', 'view:audit'=>'Прегледај ја ревизијата', 'view:system_log'=>'Прегледај системски логови',
    ];

    /** Groups of resources shown together in the permission form. */
    public const GROUPS = [
        'Проекти'=>['project', 'project_report', 'project_share'], 'Ризици'=>['risk'],
        'Буџет и финансии'=>['budget', 'allocation', 'execution', 'commitment', 'expenditure', 'budget_change'],
        'Одобрувања'=>['workflow'], 'Предмети и документи'=>['case', 'attachment', 'document_link'],
        'Правна служба'=>['legal_office'], 'Извештаи и увози'=>['report', 'import'], 'Администрација'=>['user', 'audit', 'system_log'],
    ];

    public function __construct(private readonly Database $db) {}

    public function roles(): array
    {
        return $this->db->all("SELECT r.id,r.code,r.name,r.description,r.base_role_code,r.is_system,r.active,b.name base_role_name,
                (SELECT COUNT(*) FROM permission_role pr WHERE pr.role_id=r.id) permissions,
                (SELECT COUNT(DISTINCT urs.user_id) FROM user_role_scopes urs JOIN users u ON u.id=urs.user_id WHERE urs.role_id=r.id AND u.status='active' AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())) users
            FROM roles r LEFT JOIN roles b ON b.code=r.base_role_code ORDER BY r.is_system DESC,r.name");
    }

    public function role(int $id): ?array
    {
        return $this->db->one('SELECT r.*,b.name base_role_name FROM roles r LEFT JOIN roles b ON b.code=r.base_role_code WHERE r.id=:id', ['id'=>$id]);
    }

    /** System roles a new role can be built on (not the system administrator). */
    public function baseRoles(): array
    {
        return $this->db->all("SELECT id,code,name FROM roles WHERE is_system=1 AND active=1 AND code<>'system_admin' ORDER BY name");
    }

    /** Permissions grouped for the form: [group => [[id, key, label]]]. */
    public function permissionGroups(): array
    {
        $byResource = [];
        foreach ($this->db->all('SELECT id,action,resource_type,description FROM permissions ORDER BY resource_type,action') as $p) {
            $key = $p['action'].':'.$p['resource_type'];
            $byResource[(string) $p['resource_type']][] = ['id'=>(int) $p['id'], 'key'=>$key, 'label'=>self::LABELS[$key] ?? ((string) ($p['description'] ?? '') ?: $key)];
        }
        $out = [];
        foreach (self::GROUPS as $group => $resources) foreach ($resources as $r) if (isset($byResource[$r])) { $out[$group] = array_merge($out[$group] ?? [], $byResource[$r]); unset($byResource[$r]); }
        foreach ($byResource as $r => $items) $out['Други'] = array_merge($out['Други'] ?? [], $items);
        return $out;
    }

    public function permissionIds(int $roleId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT permission_id FROM permission_role WHERE role_id=:role', ['role'=>$roleId]), 'permission_id'));
    }

    /** Creates a role on a base role; its permissions start as a copy of the base role's when $copy. Returns the id. */
    public function create(string $code, string $name, string $description, string $base, bool $copy): int
    {
        $code = mb_strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $code)) throw new \InvalidArgumentException('Шифрата на улогата мора да има 3–50 мали латинични букви, бројки или _, и да почнува со буква.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM roles WHERE LOWER(code)=:code', ['code'=>$code]) > 0) throw new \InvalidArgumentException('Веќе постои улога со оваа шифра.');
        [$name, $description] = $this->texts($name, $description);
        $baseRole = $this->db->one("SELECT id,code,financial_role FROM roles WHERE code=:code AND is_system=1 AND active=1 AND code<>'system_admin'", ['code'=>$base]);
        if (!$baseRole) throw new \InvalidArgumentException('Изберете основна улога (однесувањето на улогата во модулите).');
        return (int) $this->db->transaction(function () use ($code, $name, $description, $baseRole, $copy): int {
            $id = $this->db->insertId('INSERT INTO roles(code,name,description,base_role_code,is_system,active,financial_role,created_at,updated_at) VALUES(:code,:name,:description,:base,0,1,:financial,SYSUTCDATETIME(),SYSUTCDATETIME())', ['code'=>$code, 'name'=>$name, 'description'=>$description, 'base'=>$baseRole['code'], 'financial'=>(int) ($baseRole['financial_role'] ?? 0)]);
            if ($copy) $this->db->execute('INSERT INTO permission_role(permission_id,role_id) SELECT permission_id,:role FROM permission_role WHERE role_id=:base', ['role'=>$id, 'base'=>$baseRole['id']]);
            return $id;
        });
    }

    /**
     * Updates name, description and permissions; for a role created in the application also its base role.
     * Returns [before, after] permission ids.
     */
    public function update(int $id, string $name, string $description, ?string $base, array $permissionIds): array
    {
        $role = $this->role($id);
        if (!$role) throw new \InvalidArgumentException('Улогата не е пронајдена.');
        [$name, $description] = $this->texts($name, $description);
        $wanted = array_values(array_unique(array_map('intval', $permissionIds)));
        $known = array_map('intval', array_column($this->db->all('SELECT id FROM permissions'), 'id'));
        if (array_diff($wanted, $known) !== []) throw new \InvalidArgumentException('Една од избраните дозволи не постои.');
        $baseCode = $role['base_role_code'];
        if (!(int) $role['is_system'] && $base !== null && $base !== $role['base_role_code']) {
            if (!$this->db->scalar("SELECT COUNT(*) FROM roles WHERE code=:code AND is_system=1 AND active=1 AND code<>'system_admin'", ['code'=>$base])) throw new \InvalidArgumentException('Основната улога не е валидна.');
            $baseCode = $base;
        }
        $before = $this->permissionIds($id);
        $this->db->transaction(function () use ($id, $role, $name, $description, $baseCode, $wanted): void {
            $this->db->execute('UPDATE roles SET name=:name,description=:description,base_role_code=:base,updated_at=SYSUTCDATETIME() WHERE id=:id', ['name'=>$name, 'description'=>$description, 'base'=>$baseCode, 'id'=>$id]);
            if ($role['code'] === 'system_admin') return; // always every permission
            $this->db->execute('DELETE FROM permission_role WHERE role_id=:role', ['role'=>$id]);
            foreach ($wanted as $permissionId) $this->db->execute('INSERT INTO permission_role(permission_id,role_id) VALUES(:permission,:role)', ['permission'=>$permissionId, 'role'=>$id]);
        });
        return [$before, $role['code'] === 'system_admin' ? $before : $wanted];
    }

    /** Roles created in the application can be deactivated (they then give no access) and activated again. */
    public function setActive(int $id, bool $active): void
    {
        $role = $this->role($id);
        if (!$role) throw new \InvalidArgumentException('Улогата не е пронајдена.');
        if ((int) $role['is_system']) throw new \InvalidArgumentException('Системските улоги не се деактивираат; отстранете ги пристапите на корисниците или променете ги дозволите.');
        $this->db->execute('UPDATE roles SET active=:active,updated_at=SYSUTCDATETIME() WHERE id=:id', ['active'=>$active ? 1 : 0, 'id'=>$id]);
    }

    /** Deletes a role created in the application that was never assigned. */
    public function delete(int $id): array
    {
        $role = $this->role($id);
        if (!$role) throw new \InvalidArgumentException('Улогата не е пронајдена.');
        if ((int) $role['is_system']) throw new \InvalidArgumentException('Системските улоги не се бришат.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM user_role_scopes WHERE role_id=:id', ['id'=>$id]) > 0) throw new \InvalidArgumentException('Улогата е доделена на корисници (и во минатото) и не се брише. Деактивирајте ја.');
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM permission_role WHERE role_id=:id', ['id'=>$id]);
            $this->db->execute('DELETE FROM roles WHERE id=:id', ['id'=>$id]);
        });
        return $role;
    }

    private function texts(string $name, string $description): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) throw new \InvalidArgumentException('Називот на улогата мора да има од 3 до 100 знаци.');
        $description = trim($description);
        if (mb_strlen($description) > 500) throw new \InvalidArgumentException('Описот е предолг.');
        return [$name, $description !== '' ? $description : null];
    }
}
