<?php

declare(strict_types=1);

use EKabineti\Core\Database;
use EKabineti\Core\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$app = require dirname(__DIR__).'/app/bootstrap.php';
$db = $app->database();

try {
    $existing = (int) $db->scalar(
        "SELECT COUNT(*)
         FROM dbo.users AS u
         INNER JOIN dbo.user_role_scopes AS urs ON urs.user_id = u.id
         INNER JOIN dbo.roles AS r ON r.id = urs.role_id
         WHERE r.code = :role
           AND u.status = :status
           AND urs.valid_from <= SYSUTCDATETIME()
           AND (urs.valid_to IS NULL OR urs.valid_to > SYSUTCDATETIME())",
        ['role' => 'system_admin', 'status' => 'active']
    );
    if ($existing > 0) {
        throw new RuntimeException('Веќе постои активен администратор. Почетното создавање е откажано.');
    }

    $username = mb_strtolower(prompt('Administrator username: '));
    $email = mb_strtolower(prompt('Administrator email: '));
    $name = prompt('Administrator full name: ');
    $password = secret('Administrator password: ');
    $confirmation = secret('Repeat administrator password: ');

    validate($username, $email, $name, $password, $confirmation);

    $duplicate = (int) $db->scalar(
        'SELECT COUNT(*) FROM dbo.users WHERE LOWER(username) = :username OR LOWER(email) = :email',
        ['username' => $username, 'email' => $email]
    );
    if ($duplicate > 0) {
        throw new RuntimeException('Корисничкото име или е-поштата веќе постојат.');
    }

    $roleId = $db->scalar('SELECT id FROM dbo.roles WHERE code = :code', ['code' => 'system_admin']);
    if ($roleId === null) {
        throw new RuntimeException('Улогата system_admin недостасува во базата.');
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $password = $confirmation = '';
    if ($passwordHash === false) {
        throw new RuntimeException('Генерирањето на хашот на лозинката не успеа.');
    }

    $userId = $db->transaction(static function (Database $db) use ($name, $username, $email, $passwordHash, $roleId): int {
        $userId = $db->insertId(
            'INSERT INTO dbo.users
                (name,username,email,email_verified_at,password,locale,status,last_login_at,remember_token,created_at,updated_at)
             VALUES
                (:name,:username,:email,SYSUTCDATETIME(),:password,:locale,:status,NULL,NULL,SYSUTCDATETIME(),SYSUTCDATETIME())',
            ['name'=>$name,'username'=>$username,'email'=>$email,'password'=>$passwordHash,'locale'=>'mk','status'=>'active']
        );
        $db->execute(
            'INSERT INTO dbo.user_role_scopes
                (user_id,role_id,directorate_id,program_id,project_id,valid_from,valid_to,granted_by,reason,created_at,updated_at)
             VALUES
                (:user,:role,NULL,NULL,NULL,SYSUTCDATETIME(),NULL,:granted,:reason,SYSUTCDATETIME(),SYSUTCDATETIME())',
            ['user'=>$userId,'role'=>(int)$roleId,'granted'=>$userId,'reason'=>'Почетно поставување на продукција преку PHP 8.1 команда']
        );
        $db->execute(
            'INSERT INTO dbo.audit_events
                (actor_id,action,entity_type,entity_id,before_state,after_state,ip_address,correlation_id,reason,occurred_at)
             VALUES
                (:actor,:action,:type,:entity,NULL,:after,NULL,:correlation,:reason,SYSUTCDATETIME())',
            [
                'actor'=>$userId,
                'action'=>'administrator.bootstrap',
                'type'=>'User',
                'entity'=>$userId,
                'after'=>json_encode(['username'=>$username,'email'=>$email,'role'=>'system_admin'], JSON_UNESCAPED_UNICODE),
                'correlation'=>Request::uuid(),
                'reason'=>'Создавање на почетниот администратор на продукцијата',
            ]
        );
        return $userId;
    });

    $passwordHash = '';
    echo "[OK] Администраторот е создаден.\n";
    echo "User ID: {$userId}\nUsername: {$username}\nEmail: {$email}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function prompt(string $text): string
{
    echo $text;
    return trim((string) fgets(STDIN));
}

function secret(string $text): string
{
    echo $text;
    $hidden = DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($hidden) shell_exec('stty -echo');
    try {
        return trim((string) fgets(STDIN));
    } finally {
        if ($hidden) {
            shell_exec('stty echo');
            echo PHP_EOL;
        }
    }
}

function validate(string $username, string $email, string $name, string $password, string $confirmation): void
{
    if (!preg_match('/^[a-z0-9._-]{3,100}$/', $username)) {
        throw new RuntimeException('Корисничкото име мора да има 3-100 знаци: a-z, 0-9, точка, _ или -.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Е-поштата не е валидна.');
    }
    if ($name === '') {
        throw new RuntimeException('Името и презимето се задолжителни.');
    }
    if ($password !== $confirmation || strlen($password) < 14 || !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        throw new RuntimeException('Лозинката мора да има најмалку 14 знаци, мала буква, голема буква, број и симбол.');
    }
}
