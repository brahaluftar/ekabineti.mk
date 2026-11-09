<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$app=require dirname(__DIR__).'/app/bootstrap.php';$config=$app->config();$database=$config['database']??[];
$name=(string)($database['name']??'');$appLogin=(string)($database['username']??'');$appPassword=(string)($database['password']??'');
if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/',$name)){fwrite(STDERR,"Името на базата смее да содржи само латинични букви, бројки, _ или -.\n");exit(2);}
$collation=(string)($database['collation']??'Macedonian_FYROM_100_CI_AS');
if(!preg_match('/^[A-Za-z0-9_]{3,128}$/',$collation)){fwrite(STDERR,"database.collation не е валидна (на пр. Macedonian_FYROM_100_CI_AS).\n");exit(2);}
if(!preg_match('/^[A-Za-z0-9_.@-]{1,128}$/',$appLogin)||$appPassword===''||$appPassword==='REPLACE_ME'){fwrite(STDERR,"Пополнете ги database.username и database.password во приватната конфигурација.\n");exit(2);}

echo "Овој инсталатер ќе ја создаде тековната структура во базата: {$name}\n";
echo "SQL Server: ".($database['host']??'').":".($database['port']??1433)."\n";
echo "Колација (македонска кирилица): {$collation}\n";
echo "Не допира ниедна база што има постојни табели.\n\n";
$confirmation=valueOrPrompt('EKABINETI_INSTALL_CONFIRM',"Напишете CREATE {$name} за да продолжите: ");
if($confirmation!=="CREATE {$name}"){fwrite(STDERR,"Инсталацијата е откажана.\n");exit(2);}
$adminLogin=valueOrPrompt('EKABINETI_SQL_ADMIN_USER','Корисничко име на SQL администраторот: ');
$adminPassword=valueOrSecret('EKABINETI_SQL_ADMIN_PASSWORD','Лозинка на SQL администраторот: ');
$initialUsername=mb_strtolower(valueOrPrompt('EKABINETI_INITIAL_ADMIN_USERNAME','Корисничко име на првиот администратор на апликацијата: '));
$initialEmail=mb_strtolower(valueOrPrompt('EKABINETI_INITIAL_ADMIN_EMAIL','Е-пошта на првиот администратор: '));
$initialName=valueOrPrompt('EKABINETI_INITIAL_ADMIN_NAME','Име и презиме на администраторот: ');
$initialPassword=valueOrSecret('EKABINETI_INITIAL_ADMIN_PASSWORD','Лозинка на првиот администратор (најмалку 14 знаци): ');
$initialConfirmation=valueOrSecret('EKABINETI_INITIAL_ADMIN_PASSWORD_CONFIRM','Повторете ја лозинката на администраторот: ');
if(!preg_match('/^[a-z0-9._-]{3,100}$/',$initialUsername)||!filter_var($initialEmail,FILTER_VALIDATE_EMAIL)||$initialName===''){fwrite(STDERR,"Корисничкото име, е-поштата или името на администраторот не се валидни.\n");exit(2);}
if($initialPassword!==$initialConfirmation||strlen($initialPassword)<14||!preg_match('/[a-z]/',$initialPassword)||!preg_match('/[A-Z]/',$initialPassword)||!preg_match('/\d/',$initialPassword)||!preg_match('/[^A-Za-z0-9]/',$initialPassword)){fwrite(STDERR,"Лозинката мора да има најмалку 14 знаци, мала буква, голема буква, број и симбол.\n");exit(2);}

try{
    $master=connect($database,'master',$adminLogin,$adminPassword);
    $exists=(int)scalar($master,'SELECT COUNT(*) FROM sys.databases WHERE name=:name',['name'=>$name])===1;
    if(!scalar($master,'SELECT COUNT(*) FROM sys.fn_helpcollations() WHERE name=:c',['c'=>$collation]))throw new RuntimeException("SQL Server ја нема колацијата {$collation}.");
    if(!$exists){$master->exec('CREATE DATABASE '.identifier($name).' COLLATE '.$collation);echo "[OK] Базата на податоци {$name} е создадена со колација {$collation}.\n";}
    $actual=(string)scalar($master,"SELECT CONVERT(nvarchar(128),DATABASEPROPERTYEX(:name,'Collation'))",['name'=>$name]);
    if(stripos($actual,'Macedonian')===false&&stripos($actual,'Cyrillic')===false)echo "[ВНИМАНИЕ] Базата {$name} има колација {$actual}. Препорачана е {$collation} за правилно подредување и пребарување на кирилица.\n";
    $target=connect($database,$name,$adminLogin,$adminPassword);
    $tables=(int)scalar($target,"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'");
    if($tables>0)throw new RuntimeException("Базата на податоци {$name} има {$tables} табели. Инсталатерот одбива да ја менува.");

    if(scalar($master,'SELECT SUSER_ID(:login)',['login'=>$appLogin])===null){
        $master->exec('CREATE LOGIN '.identifier($appLogin)." WITH PASSWORD = '".str_replace("'","''",$appPassword)."', CHECK_POLICY = ON");
        echo "[OK] SQL login {$appLogin} е создаден.\n";
    }else echo "[OK] SQL login {$appLogin} веќе постои.\n";

    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');$seed=file_get_contents(dirname(__DIR__).'/database/seed-production.sql');
    if($schema===false||$seed===false)throw new RuntimeException('Недостасуваат schema.sql или seed-production.sql.');
    $target->beginTransaction();
    try{
        executeBatches($target,$schema,'schema');
        executeBatches($target,$seed,'seed');
        $userId=insertId($target,'INSERT INTO dbo.users(name,username,email,email_verified_at,password,locale,status,last_login_at,remember_token,created_at,updated_at) VALUES(:name,:username,:email,SYSUTCDATETIME(),:password,:locale,:status,NULL,NULL,SYSUTCDATETIME(),SYSUTCDATETIME())',['name'=>$initialName,'username'=>$initialUsername,'email'=>$initialEmail,'password'=>password_hash($initialPassword,PASSWORD_BCRYPT,['cost'=>12]),'locale'=>'mk','status'=>'active']);
        $roleId=(int)scalar($target,"SELECT id FROM dbo.roles WHERE code='system_admin'");
        $target->prepare('INSERT INTO dbo.user_role_scopes(user_id,role_id,directorate_id,program_id,project_id,valid_from,valid_to,granted_by,reason,created_at,updated_at) VALUES(:user,:role,NULL,NULL,NULL,SYSUTCDATETIME(),NULL,:granted,:reason,SYSUTCDATETIME(),SYSUTCDATETIME())')->execute(['user'=>$userId,'role'=>$roleId,'granted'=>$userId,'reason'=>'Почетно поставување на продукција преку PHP 8.1 инсталатерот']);
        $quotedLogin=identifier($appLogin);$loginLiteral="N'".str_replace("'","''",$appLogin)."'";
        $target->exec("IF DATABASE_PRINCIPAL_ID({$loginLiteral}) IS NULL CREATE USER {$quotedLogin} FOR LOGIN {$quotedLogin}");
        $target->exec("ALTER ROLE [db_datareader] ADD MEMBER {$quotedLogin}");
        $target->exec("ALTER ROLE [db_datawriter] ADD MEMBER {$quotedLogin}");
        $target->commit();
    }catch(Throwable$e){if($target->inTransaction())$target->rollBack();throw $e;}
    $application=connect($database,$name,$appLogin,$appPassword);$count=(int)scalar($application,"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'");
    echo "[OK] Шемата, ограничувањата и индексите се создадени.\n";
    echo "[OK] Улогите, дозволите, секторите и основните програми се создадени.\n";
    echo "[OK] Администратор {$initialUsername} е создаден.\n";
    echo "[OK] SQL најавата на апликацијата се поврзува со базата и гледа {$count} табели.\n";
    echo "Инсталацијата е успешно завршена. Сега извршете bin/preflight.php.\n";
}catch(Throwable$e){fwrite(STDERR,"[FAIL] ".$e->getMessage()."\n");fwrite(STDERR,"Ако базата штотуку е создадена, таа останува празна и инсталатерот може повторно да се изврши откако ќе се поправи проблемот.\n");exit(1);}finally{$adminPassword=$initialPassword=$initialConfirmation='';}

function connect(array$config,string$database,string$username,string$password):PDO{
    $host=(string)$config['host'];$port=(int)($config['port']??1433);$server=str_contains($host,'\\')||$port<=0?$host:'tcp:'.$host.','.$port;
    $dsn='sqlsrv:Server='.$server.';Database='.$database.';Encrypt='.(($config['encrypt']??true)?'true':'false').';TrustServerCertificate='.(($config['trust_server_certificate']??false)?'true':'false').';APP=e-Kabineti-Installer';
    if(trim((string)($config['hostname_in_certificate']??''))!=='')$dsn.=';HostnameInCertificate='.$config['hostname_in_certificate'];
    return new PDO($dsn,$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false]);
}
function scalar(PDO$pdo,string$sql,array$params=[]):mixed{$s=$pdo->prepare($sql);$s->execute($params);$value=$s->fetchColumn();return$value===false?null:$value;}
function identifier(string$value):string{return'['.str_replace(']',']]',$value).']';}
function executeBatches(PDO$pdo,string$sql,string$label):void{$batches=preg_split('/^\s*GO\s*$/mi',$sql)?:[];$number=0;foreach($batches as$batch){$batch=trim($batch);if($batch==='')continue;$number++;try{$pdo->exec($batch);}catch(Throwable$e){throw new RuntimeException("Грешка во {$label}, batch {$number}: ".$e->getMessage(),0,$e);}}}
function insertId(PDO$pdo,string$sql,array$params):int{$s=$pdo->prepare($sql.'; SELECT CAST(SCOPE_IDENTITY() AS bigint) id');$s->execute($params);do{if($s->columnCount()>0){$row=$s->fetch();if(is_array($row)&&isset($row['id']))return(int)$row['id'];}}while($s->nextRowset());throw new RuntimeException('SQL Server не врати identity.');}
function prompt(string$text):string{echo$text;return trim((string)fgets(STDIN));}
function secret(string$prompt):string{echo$prompt;$tty=DIRECTORY_SEPARATOR==='/'&&function_exists('stream_isatty')&&stream_isatty(STDIN);if($tty)shell_exec('stty -echo');$value=trim((string)fgets(STDIN));if($tty){shell_exec('stty echo');echo PHP_EOL;}return$value;}
function valueOrPrompt(string$key,string$text):string{$value=getenv($key);return$value===false?prompt($text):trim($value);}
function valueOrSecret(string$key,string$text):string{$value=getenv($key);return$value===false?secret($text):trim($value);}
