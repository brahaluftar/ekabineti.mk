<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);$env=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]as$line){if(str_starts_with(trim($line),'#')||!str_contains($line,'='))continue;[$key,$value]=explode('=',$line,2);$env[trim($key)]=trim(trim($value),"\"'");}
$env['DB_DATABASE']=getenv('EKABINETI_TEST_DATABASE') ?: 'e_kabineti_test';
$bool=static fn(string$key,bool$default):bool=>isset($env[$key])?in_array(strtolower($env[$key]),['1','true','yes','on'],true):$default;
return[
    'app'=>['env'=>'local','url'=>'http://127.0.0.1:8181','timezone'=>'Europe/Skopje','session_name'=>'ekabineti_test','health_token'=>str_repeat('a',64)],
    'database'=>['host'=>$env['DB_HOST'],'port'=>(int)$env['DB_PORT'],'name'=>$env['DB_DATABASE'],'username'=>$env['DB_USERNAME'],'password'=>$env['DB_PASSWORD'],'encrypt'=>$bool('DB_ENCRYPT',true),'trust_server_certificate'=>$bool('DB_TRUST_SERVER_CERTIFICATE',true)],
    'graph'=>['enabled'=>false],
    'google_sheets'=>['enabled'=>false,'spreadsheet_id'=>'','dashboard_gid'=>'1372017958','credentials'=>'','credentials_file'=>'','timezone'=>'Europe/Skopje','locale'=>'mk_MK'],
    'uploads'=>['requested_max_mb'=>20,'allowed_mime_types'=>['application/pdf','image/jpeg','image/png','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/csv']],
];
