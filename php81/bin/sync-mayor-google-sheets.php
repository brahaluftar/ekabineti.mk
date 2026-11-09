<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$app=require dirname(__DIR__).'/app/bootstrap.php';
$options=getopt('',['dry-run','force','configure']);
$dryRun=array_key_exists('dry-run',$options);$force=array_key_exists('force',$options);$configure=array_key_exists('configure',$options);
$config=\EKabineti\Service\MayorGoogleSheetsConfig::fromApplication($app->config());

try{
    if($configure){
        if(($config['enabled']??false)!==true)throw new RuntimeException('Enable Google Sheets sync before configuration.');
        $result=(new \EKabineti\Service\GoogleSheetsClient($config))->configureTemplate();
    }else{
        $sync=new \EKabineti\Service\MayorGoogleSheetsSync(
            new \EKabineti\Service\MayorDashboardReport($app->database()),
            new \EKabineti\Service\MayorGoogleSheetsMap(),
            $app->logger(),$config,dirname(__DIR__).'/storage'
        );
        $result=$sync->run($dryRun,$force);
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(($result['status']??'')==='failed'?1:0);
}catch(Throwable$exception){
    fwrite(STDERR,json_encode(['status'=>'failed','exception'=>get_class($exception),'error'=>$exception->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

