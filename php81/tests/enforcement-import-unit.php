<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(2);

define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string$class):void{$prefix='EKabineti\\';if(!str_starts_with($class,$prefix))return;$file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require$file;});

$checks=0;$assert=static function(bool$ok,string$message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException('Assertion failed: '.$message);echo"[OK] $message\n";};
$workflow=(new ReflectionClass(EKabineti\Service\ImportWorkflow::class))->newInstanceWithoutConstructor();
$matcher=new ReflectionMethod(EKabineti\Service\ImportWorkflow::class,'matchBudgetLine');
$lines=[['id'=>1,'code'=>'0660','name'=>'Kodi 0660'],['id'=>2,'code'=>'0451','name'=>'Kodi 0451']];

$result=$matcher->invoke($workflow,'Pagesë sipas kodit buxhetor 0660','',$lines);
$assert($result['status']==='linked'&&$result['budget_line_id']===1,'budget code is extracted from description and matched only to an existing line');
$result=$matcher->invoke($workflow,'Vendim gjyqësor','10/616/18040/34000/00040/0451',$lines);
$assert($result['status']==='linked'&&$result['budget_line_id']===2,'coding-block segment is a secondary matching source');
$result=$matcher->invoke($workflow,'Kodet 0660 dhe 0451','',$lines);
$assert($result['status']==='budget_code_ambiguous'&&$result['budget_line_id']===null,'ambiguous official-code matches are rejected');
$result=$matcher->invoke($workflow,'Kodi buxhetor 0660','10/616/18040/34000/00040/0451',$lines);
$assert($result['status']==='budget_code_mismatch'&&$result['budget_line_id']===null,'description and coding-block mismatch is rejected');
$result=$matcher->invoke($workflow,'Kodi 9999','',$lines);
$assert($result['status']==='budget_code_unknown'&&$result['budget_line_id']===null,'unknown codes are not created automatically');

$source=(string)file_get_contents(APP_ROOT.'/app/Service/ImportWorkflow.php');
$assert(str_contains($source,"unset(\$hashPayload['source_row_number'],\$hashPayload['budget_line_id'],\$hashPayload['extracted_budget_code'])")&&str_contains($source,"'key'=>'row-'.\$rowNumber.':'.\$sourceKey"),'deduplication is stable when row positions or derived budget links change');
$assert(str_contains($source,"str_contains(\$joined, 'totali_per_valute')")&&str_contains($source,"'control_totals_mismatch'"),'dynamic control-total rows validate the imported transactions');

echo"PASS: $checks enforcement import checks.\n";
