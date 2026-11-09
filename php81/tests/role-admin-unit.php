<?php
declare(strict_types=1);

// Dynamic roles: created on a system role (behaviour), permissions chosen one by one, system roles protected,
// inactive roles give no access, Scope uses the base role for role checks and the role's own permissions for can().
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

final class TsqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('; SELECT CAST(SCOPE_IDENTITY() AS bigint) AS id', ' RETURNING id', $query);
        $query = preg_replace('/SELECT TOP (\d+) (.*)$/s', 'SELECT $2 LIMIT $1', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = new TsqlOnSqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('SYSUTCDATETIME', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
foreach ([
    'users(id INTEGER PRIMARY KEY,name TEXT,status TEXT)',
    'roles(id INTEGER PRIMARY KEY,code TEXT,name TEXT,description TEXT,base_role_code TEXT,is_system INT DEFAULT 0,active INT DEFAULT 1,financial_role INT DEFAULT 0,created_at TEXT,updated_at TEXT)',
    'permissions(id INTEGER PRIMARY KEY,action TEXT,resource_type TEXT,description TEXT)',
    'permission_role(permission_id INT,role_id INT,PRIMARY KEY(permission_id,role_id))',
    'user_role_scopes(id INTEGER PRIMARY KEY,user_id INT,role_id INT,directorate_id INT,program_id INT,project_id INT,valid_from TEXT,valid_to TEXT)',
] as $t) $pdo->exec('CREATE TABLE '.$t);
$pdo->exec("INSERT INTO users VALUES(1,'Admin','active'),(2,'Arta Provë','active'),(3,'Петар Пробен','active')");
$pdo->exec("INSERT INTO roles(id,code,name,is_system,financial_role) VALUES(1,'system_admin','Администратор на системот',1,1),(2,'reporter','Известувач',1,0),(3,'director','Drejtor',1,1)");
$pdo->exec("INSERT INTO permissions VALUES(1,'view','project','View projects'),(2,'create','project','Create projects'),(3,'view','risk','View risks'),(4,'view','budget','View budget'),(5,'manage','user','Manage users'),(6,'view','legacy_thing','Legacy')");
$pdo->exec("INSERT INTO permission_role VALUES(1,2),(3,2),(1,3),(2,3),(3,3),(4,3)");

$db = (new ReflectionClass(EKabineti\Core\Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EKabineti\Core\Database::class, 'pdo'))->setValue($db, $pdo);
$admin = new EKabineti\Service\RoleAdmin($db);
$fail = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };

$assert(array_column($admin->baseRoles(), 'code') === ['director', 'reporter'], 'base roles are the system roles except the system administrator');
$assert(str_contains($fail(fn () => $admin->create('X', 'Roli X', '', 'reporter', true)), 'Шифрата на улогата'), 'the code is 3–50 lower-case letters, digits or _');
$assert(str_contains($fail(fn () => $admin->create('reporter', 'Известувач 2', '', 'reporter', true)), 'Веќе постои'), 'codes are unique');
$assert(str_contains($fail(fn () => $admin->create('super_role', 'Super', '', 'system_admin', true)), 'основна улога'), 'a role cannot be built on the system administrator');
$assert(str_contains($fail(fn () => $admin->create('x_role', 'ab', '', 'reporter', true)), 'Називот на улогата'), 'the name has 3–100 letters');

$id = $admin->create('Civil_Status_Officer', '  Службеник за   матична евиденција ', 'Raporton sektorin', 'reporter', true);
$role = $admin->role($id);
$assert($role['code'] === 'civil_status_officer' && $role['name'] === 'Службеник за матична евиденција' && $role['base_role_code'] === 'reporter' && (int) $role['is_system'] === 0 && (int) $role['active'] === 1, 'a role is created on its base role');
$perms = $admin->permissionIds($id); sort($perms);
$assert($perms === [1, 3], 'it starts with the permissions of the base role');
$plain = $admin->create('plain_role', 'Улога без дозволи', '', 'director', false);
$assert($admin->permissionIds($plain) === [] && (int) $admin->role($plain)['financial_role'] === 1, 'without copying it starts empty and takes the financial flag of its base');

[$was, $now] = $admin->update($id, 'Службеник за матична евиденција', '', 'director', [1, 4, 4]);
$assert($now === [1, 4] && $admin->role($id)['base_role_code'] === 'director' && $admin->role($id)['description'] === null, 'permissions are replaced, the base can change for a created role');
$assert(str_contains($fail(fn () => $admin->update($id, 'Службеник', '', 'director', [99])), 'не постои'), 'unknown permissions are refused');
$assert(str_contains($fail(fn () => $admin->update($id, 'Службеник', '', 'system_admin', [1])), 'Основната улога'), 'the base cannot become the system administrator');
$admin->update(2, 'Известувач', 'Raporton', 'director', [1]);
$assert($admin->role(2)['base_role_code'] === null && $admin->permissionIds(2) === [1], 'a system role keeps its code and base, its permissions change');
$admin->update(1, 'Администратор на системот', '', null, []);
$assert($admin->permissionIds(1) === [], 'the system administrator keeps its permission rows untouched (it always has every permission)');

$assert(str_contains($fail(fn () => $admin->setActive(2, false)), 'Системските улоги'), 'system roles are not deactivated');
$assert(str_contains($fail(fn () => $admin->delete(3)), 'не се бришат'), 'system roles are not deleted');

// Scope: the base role decides the behaviour, the role's own permissions decide can().
$pdo->exec("INSERT INTO user_role_scopes(user_id,role_id,directorate_id) VALUES(2,{$id},7),(3,2,NULL)");
$scope = new EKabineti\Security\Scope($db);
$assert(in_array('director', $scope->roles(2), true) && !in_array('civil_status_officer', $scope->roles(2), true), 'a created role behaves as its base role in role checks');
$assert($scope->can(2, 'view', 'budget') && $scope->can(2, 'view', 'project') && !$scope->can(2, 'create', 'project'), 'can() follows the chosen permissions, not those of the base role');
$assert($scope->canManageCitizenSectors(2, 7) && !$scope->canManageCitizenSectors(2, 8), 'directorate scope works through the base role');
$admin->setActive($id, false);
$scope = new EKabineti\Security\Scope($db);
$assert($scope->roles(2) === [] && !$scope->can(2, 'view', 'budget'), 'an inactive role gives no access');
$assert(str_contains($fail(fn () => $admin->delete($id)), 'доделена на корисници'), 'an assigned role is not deleted');
$admin->delete($plain);
$assert($admin->role($plain) === null, 'a role never assigned is deleted with its permissions');

$groups = $admin->permissionGroups();
$labels = []; foreach ($groups as $g => $items) foreach ($items as $p) $labels[$p['key']] = [$g, $p['label']];
$assert($labels['view:budget'] === ['Буџет и финансии', 'Прегледај го буџетот'] && $labels['manage:user'][0] === 'Администрација' && $labels['view:legacy_thing'] === ['Други', 'Legacy'], 'permissions are grouped with Albanian labels; unknown ones fall back to their description');

// Wiring and migration.
$sql = (string) file_get_contents(APP_ROOT.'/database/migrations/2026_11_08_004800_dynamic_roles.sql');
$assert(str_contains($sql, "ADD base_role_code nvarchar(50) NULL") && str_contains($sql, "UPDATE dbo.roles SET is_system=1") && str_contains($sql, "(N'manage',N'user',N'Администрирај корисници и пристапи')"), 'migration: base role, system flag and the permissions the application checks');
$app = (string) file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($app, "\$p==='/roles')\$this->roles()->index()") && str_contains($app, "#^/roles/(\\d+)/delete$#"), 'role routes');
$controller = (string) file_get_contents(APP_ROOT.'/app/Controller/RoleController.php');
$assert(substr_count($controller, '$this->requireAdmin();') === 6 && !preg_match('/style=/', $controller), 'every role action is for the system administrator; no inline styles');
$scopeSrc = (string) file_get_contents(APP_ROOT.'/app/Security/Scope.php');
$assert(str_contains($scopeSrc, 'COALESCE(r.base_role_code,r.code) role_code') && str_contains($scopeSrc, 'JOIN roles r ON r.id=urs.role_id AND r.active=1 JOIN permission_role'), 'Scope uses the base role and ignores inactive roles');
$write = (string) file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($write, "WHERE id=:id AND active=1',['id'=>\$roleId]") && str_contains($write, "\$global=in_array(\$role['behaviour_code'],"), 'only active roles are assigned; global roles are recognised through their base');
$assert(!preg_match("/(?<![\\w.(])r\\.code( IN ?\\(|=)/", $write.(string) file_get_contents(APP_ROOT.'/app/Controller/ReadController.php').(string) file_get_contents(APP_ROOT.'/app/Service/Scheduler.php')), 'role-code SQL checks follow the base role');

if ($failures) { fwrite(STDERR, "FAIL\n - ".implode("\n - ", $failures)."\n"); exit(1); }
echo "role-admin-unit: OK\n";
