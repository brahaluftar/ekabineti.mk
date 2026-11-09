<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(2);

define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string$class):void{$prefix='EKabineti\\';if(!str_starts_with($class,$prefix))return;$file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require$file;});

$checks=0;$assert=static function(bool$ok,string$message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException('Assertion failed: '.$message);echo"[OK] $message\n";};
$projects=[
    ['name'=>'A','executive_status'=>'On Track','progress_ratio'=>0.50,'next_deadline'=>'2026-08-31','risk_level'=>'Low'],
    ['name'=>'B','executive_status'=>'At Risk','progress_ratio'=>0.70,'next_deadline'=>'2026-09-20','risk_level'=>'High'],
    ['name'=>'C','executive_status'=>'Completed','progress_ratio'=>1.00,'next_deadline'=>null,'risk_level'=>'Low'],
];
$services=[['service_name'=>'Civil','received_count'=>100,'resolved_count'=>80,'open_count'=>20,'resolution_ratio'=>0.8,'average_resolution_hours'=>10.0,'sla_target_hours'=>24.0]];
$budget=[['directorate'=>'D1','annual_budget'=>1000.0,'committed'=>700.0,'spent'=>500.0]];
$issues=[['issue'=>'Critical issue','level'=>'Critical','status'=>'Open','deadline'=>'2026-09-01'],['issue'=>'High issue','level'=>'High','status'=>'Open','deadline'=>'2026-09-02']];
$metrics=(new EKabineti\Service\MayorDashboardMetrics())->calculate($projects,$services,$budget,$issues,'2026-08-30');
$assert(abs((float)$metrics['average_project_progress']-(2.2/3))<0.000001,'average project progress formula');
$assert($metrics['municipal_pulse']===77,'municipal pulse 40/40/20 formula');
$assert($metrics['deadlines_14_days']===1,'inclusive fourteen-day deadline count');
$assert($metrics['at_risk_projects']===1&&$metrics['active_projects']===2,'project status metrics');
$assert((float)$metrics['budget_execution']===0.5,'budget execution formula');

$snapshot=['generated_at'=>'2026-08-30T00:00:00Z','as_of_date'=>'2026-08-30','synchronized_at'=>'30.08.2026 14:15:16','synchronization_timezone'=>'Europe/Budapest','projects'=>$projects,'services'=>$services,'budget'=>$budget,'issues'=>$issues,'procurement'=>[['procurement_code'=>'P-1','contract_code'=>'K-1','contract_title'=>'Договори','total'=>1200,'total_paid_amount'=>300]],'legal_cases'=>[['record_number'=>'E-7','source_type'=>'enforcement_execution','case_type'=>'execution','status'=>'classified','reference_date'=>'2026-08-20','journal_voucher_number'=>'2026-1','net_amount'=>125.5,'budget_code'=>'0660']], 'metrics'=>$metrics,'data_gaps'=>[]];
$batch=(new EKabineti\Service\MayorGoogleSheetsMap())->valuesBatch($snapshot);$ranges=array_column($batch,'range');
$timestampBlock=$batch[array_search("'Панел'!L4:M5",$ranges,true)]['values'];
$assert($timestampBlock===[['СИНХРОНИЗАЦИЈА','ВРЕМЕНСКА ЗОНА'],['30.08.2026 14:15:16','Europe/Budapest']],'Paneli publishes the synchronization date time and timezone');
$assert(in_array("'Панел'!B15:F20",$ranges,true)&&in_array("'Панел'!H15:L17",$ranges,true)&&in_array("'Панел'!B24:H35",$ranges,true),'fixed Paneli output ranges');
$assert(!in_array("'Панел'!B50:M55",$ranges,true),'Mayor notes are never synchronization-owned');
$projectBlock=$batch[array_search("'Проекти'!A2:L1000",$ranges,true)]['values'];
$assert(count($projectBlock)===999&&count($projectBlock[0])===12,'project detail range is fixed and stale rows are blanked');
$assert($projectBlock[3]===array_fill(0,12,''),'shorter detail payload clears stale values without deleting rows');
$serviceBlock=$batch[array_search("'Услуги'!A2:G500",$ranges,true)]['values'];
$assert(count($serviceBlock)===499&&$serviceBlock[0][4]===0.8,'civil service detail mapping preserves numeric percentage');
$procurementBlock=$batch[array_search("'Јавни набавки'!A1:R2000",$ranges,true)]['values'];
$assert(count($procurementBlock)===2000&&count($procurementBlock[0])===18&&$procurementBlock[1][0]==='P-1'&&$procurementBlock[1][15]===300.0,'procurement is published in its separate fixed sheet');
$legalBlock=$batch[array_search("'Правни предмети'!A1:T2000",$ranges,true)]['values'];
$assert(count($legalBlock)===2000&&count($legalBlock[0])===20&&$legalBlock[1][0]==='E-7'&&$legalBlock[1][13]===125.5,'legal and enforcement cases are published in their separate Mayor Pulse sheet');

$blankMetrics=(new EKabineti\Service\MayorDashboardMetrics())->calculate($projects,[],$budget,$issues,'2026-08-30');
$assert($blankMetrics['service_resolution']===null&&$blankMetrics['municipal_pulse']===null,'empty civil KPI view produces blanks instead of fabricated values');
$blankBatch=(new EKabineti\Service\MayorGoogleSheetsMap())->valuesBatch(['synchronized_at'=>'30.08.2026 14:15:16','synchronization_timezone'=>'Europe/Budapest','projects'=>$projects,'services'=>[],'budget'=>$budget,'issues'=>$issues,'metrics'=>$blankMetrics]);
$blankBatch=EKabineti\Service\GoogleSheetsClient::normalizeValuesBatch($blankBatch);
$blankRanges=array_column($blankBatch,'range');
$cell=static function(array$payload,array$ranges,string$range):mixed{return$payload[array_search($range,$ranges,true)]['values'][0][0];};
$assert($cell($blankBatch,$blankRanges,"'Панел'!B5")==='','missing municipal pulse is an empty Google Sheets cell');
$assert($cell($blankBatch,$blankRanges,"'Панел'!B10")==='','missing service resolution is an empty Google Sheets cell');
$assert($cell($blankBatch,$blankRanges,"'Панел'!D10")==='','missing average resolution time is an empty Google Sheets cell');
$serviceTotal=$blankBatch[array_search("'Панел'!H47:L47",$blankRanges,true)]['values'][0];
$assert($serviceTotal===['ВКУПНО','','','',''],'missing service totals and KPIs are empty cells, not zero');

$missingProject=[['name'=>'Missing values','project_budget'=>null,'spent'=>null,'progress_ratio'=>null,'next_deadline'=>null]];
$missingBatch=(new EKabineti\Service\MayorGoogleSheetsMap())->valuesBatch(['projects'=>$missingProject,'services'=>[],'budget'=>[],'issues'=>[],'metrics'=>[]]);
$missingRanges=array_column($missingBatch,'range');$missingRow=$missingBatch[array_search("'Проекти'!A2:L1000",$missingRanges,true)]['values'][0];
$assert($missingRow[3]===''&&$missingRow[4]===''&&$missingRow[6]===''&&$missingRow[7]==='','other missing detail values publish as empty cells');

$normalized=EKabineti\Service\GoogleSheetsClient::normalizeValuesBatch([['range'=>'Test!A1:C2','values'=>[[null,0,'null'],[1.5,null,'N/A']]]]);
$assert($normalized[0]['values'][0]===['',0,'null']&&$normalized[0]['values'][1]===[1.5,'','N/A'],'final payload boundary recursively converts only null to empty strings');
$containsNull=static function(mixed$value)use(&$containsNull):bool{if($value===null)return true;if(!is_array($value))return false;foreach($value as$nested)if($containsNull($nested))return true;return false;};
$assert(!$containsNull($blankBatch),'normalized values.batchUpdate payload contains no null values');

echo"PASS: $checks checks.\n";
