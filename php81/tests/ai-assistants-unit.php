<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(2);

define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string$class):void{$prefix='EKabineti\\';if(!str_starts_with($class,$prefix))return;$file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require$file;});

$tests=0;$assert=static function(bool$ok,string$message)use(&$tests):void{$tests++;if(!$ok)throw new RuntimeException('Assertion failed: '.$message);echo"[OK] $message\n";};
$captured=null;
$transport=static function(array$payload)use(&$captured):array{$captured=$payload;return['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'{"action_key":"none","risk_level":"low"}']]]]];};
$client=new EKabineti\Service\OpenAIResponsesClient(['enabled'=>true,'api_key'=>'unit-test-secret','model'=>'gpt-5-mini','timeout_seconds'=>10,'max_output_tokens'=>900],$transport);
$result=$client->structured('Policy','Untrusted input','test_schema',['type'=>'object','additionalProperties'=>false,'properties'=>['action_key'=>['type'=>'string'],'risk_level'=>['type'=>'string']],'required'=>['action_key','risk_level']]);
$assert($result['action_key']==='none'&&$result['risk_level']==='low','structured Responses output is parsed');
$assert(($captured['store']??null)===false&&($captured['model']??'')==='gpt-5-mini','Responses request disables storage and uses configured model');
$assert(($captured['text']['format']['type']??'')==='json_schema'&&($captured['text']['format']['strict']??false)===true,'strict JSON schema output is requested');
$assert(!str_contains(json_encode($captured,JSON_THROW_ON_ERROR),'unit-test-secret'),'API key is not included in the JSON request body');
$assert(EKabineti\Service\AiInterventionExecutor::ACTIONS===['none','run_scheduler','run_migrations','sync_mayor_google_sheets'],'technical intervention allowlist is closed');
$disabled=new EKabineti\Service\OpenAIResponsesClient(['enabled'=>false,'api_key'=>'present']);
$assert(!$disabled->isConfigured(),'disabled OpenAI configuration is not callable');

echo"PASS: $tests AI assistant unit checks.\n";
