<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function(string$class):void{$prefix='EKabineti\\';if(str_starts_with($class,$prefix)){$file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require$file;}});
$config=require __DIR__.'/local-config.php';
$db=new EKabineti\Core\Database($config['database']);
$result=(new EKabineti\Service\MigrationRunner($db,APP_ROOT.'/database/migrations'))->run();
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
