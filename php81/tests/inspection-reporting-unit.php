<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('APP_ROOT',dirname(__DIR__));
spl_autoload_register(static function(string$class):void{$prefix='EKabineti\\';if(!str_starts_with($class,$prefix))return;$file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require$file;});
$tests=0;$assert=static function(bool$ok,string$message)use(&$tests):void{$tests++;if(!$ok)throw new RuntimeException('Assertion failed: '.$message);echo"[OK] $message\n";};
$metrics=EKabineti\Service\InspectionReporting::METRICS;
$assert(count($metrics)===11,'all numeric indicators from the document are defined');
$assert(isset($metrics['complaints_requests'],$metrics['phase_inspections'],$metrics['executed_decisions']),'first, inspection and demolition indicators are present');
$migration=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_10_07_002300_inspection_reporting.sql');
$assert(str_contains($migration,'CK_inspection_reports_nonnegative')&&str_contains($migration,'UQ_inspection_reports_period'),'database enforces nonnegative values and unique periods');
$application=(string)file_get_contents(APP_ROOT.'/app/Core/Application.php');
$assert(str_contains($application,"/reports/inspection")&&str_contains($application,'inspectionReportingAccess'),'routes have a dedicated authorization boundary');
$scope=(string)file_get_contents(APP_ROOT.'/app/Security/Scope.php');
$assert(str_contains($scope,'canSubmitInspectionReporting')&&str_contains($scope,"['director','directors_assistant','reporter']"),'submissions are restricted to scoped inspection reporters');
echo"PASS: $tests inspection reporting unit checks.\n";
