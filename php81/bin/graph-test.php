<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$recipient=$argv[1]??'';if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php bin/graph-test.php recipient@example.com\n");exit(2);}
$app=require dirname(__DIR__).'/app/bootstrap.php';$graph=new EKabineti\Service\GraphMail($app->config()['graph']??[]);
try{$id=$graph->send($recipient,'Тест на известувањата · е-Кабинет','Microsoft Graph е успешно конфигуриран.');echo 'Graph accepted sendMail with HTTP 202. Request ID: '.($id?:'not returned').PHP_EOL;}catch(Throwable$e){$app->logger()->write('error','graph.test_failed',['error'=>$e->getMessage()]);fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

