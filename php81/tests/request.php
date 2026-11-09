<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']=$argv[1]??'/login';
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $_GET);
$_SERVER['SCRIPT_NAME']='/index.php';
$app=require dirname(__DIR__).'/app/bootstrap.php';
if(isset($argv[2])){
    if($argv[2]==='first')$_SESSION['user_id']=(int)$app->database()->scalar("SELECT TOP 1 id FROM users WHERE status='active' ORDER BY id");
    elseif(in_array($argv[2],['system_admin','mayor','mayor_admin','director','directors_assistant','reporter'],true))$_SESSION['user_id']=(int)$app->database()->scalar("SELECT TOP 1 u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND r.code=:role AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.id",['role'=>$argv[2]]);
    else $_SESSION['user_id']=(int)$argv[2];
}
$app->run();
