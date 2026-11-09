<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void { if (str_starts_with($class, 'EKabineti\\')) { $file = APP_ROOT.'/app/'.str_replace('\\', '/', substr($class, 10)).'.php'; if (is_file($file)) require $file; } });
$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$migration=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_10_26_003700_citizen_service_windows.sql');
$read=(string)file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$write=(string)file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');

$assert(str_contains($migration,"CHECK (window_type IN (N'request',N'subsidy',N'complaint',N'volume'))")&&str_contains($migration,"N'informational',N'volume',N'amount'")&&str_contains($migration,'amount_value decimal(19,2) NULL'),'windows have a type, and the volume and amount roles exist with euro amounts in two decimals');
foreach(['DHEALTH_SUBSIDIES','DHEALTH_SUBSIDY_COMPLAINTS','DHEALTH_VISITS_QKMF','DHEALTH_VISITS_QMU','DHEALTH_VISITS_DENTAL'] as$code)$assert(str_contains($migration,"N'".$code."'"),'Health window '.$code.' is in the catalog');
$assert(str_contains($migration,"N'Жалби за здравствени услуги'")&&!str_contains($migration,"N'Здравствени жалби'"),'Health complaints are split: services and subsidies');
foreach(['DBUJQ','KULT','DMQS','DRIN','DSPORT','DTUR','DIG'] as$d)$assert(str_contains($migration,"N'".$d."_SUBSIDY_COMPLAINTS'"),'directorate '.$d.' with subsidies gets a subsidy-complaints window');
$assert(str_contains($migration,"(N'subsidy',N'approved_amount',N'Одобрен износ (ден.)',N'amount',50)")&&str_contains($migration,"(N'complaint',N'unfounded',N'Неосновани',N'resolved',30)"),'subsidy and complaint windows use the standard fields');
$assert(str_contains($migration,"WHERE s.window_type<>N'request' AND m.active=1")&&str_contains($migration,'target.active=1'),'standardized windows ask only their standard fields; history is kept');
$assert(str_contains($migration,"WHERE s.window_type<>N'volume';")&&str_contains($migration,'CREATE OR ALTER VIEW dbo.vw_citizen_service_directorate_weeks'),'visits stay out of the request KPIs and directorate totals have their own view');
$assert(str_contains($migration,'open_after_forwarding')&&str_contains($migration,"DATEADD(day,-(DATEDIFF(day,CONVERT(date,'19000101',112),forwarded_on)%7),forwarded_on)"),'forwarded cases are counted per Monday week and subtracted from the open ones, never below zero');
$assert(str_contains($read,"'volume'=>'Посети и дадени услуги'")&&str_contains($read,'FROM vw_citizen_service_directorate_weeks w WHERE w.week_start=:summary_week')&&str_contains($read,'Во постапка по препраќањето'),'the report page groups windows by type and shows the directorate totals after forwarding');
$assert(str_contains($write,"if (\$metric['metric_role'] === 'amount')")&&str_contains($write,"'amount'=>\$amounts[\$metricId] ?? null"),'euro amounts are validated with two decimals and saved apart from counts');

$map=new EKabineti\Service\MayorGoogleSheetsMap();
$batch=$map->valuesBatch(['projects'=>[],'services'=>[],'budget'=>[],'issues'=>[],'metrics'=>[],'procurement'=>[],'legal_cases'=>[],'service_directorates'=>[['directorate_code'=>'DHEALTH','directorate_name'=>'Здравство','week_start'=>'2026-10-19','week_end'=>'2026-10-25','received_count'=>66,'resolved_count'=>46,'reported_open_count'=>20,'forwarded_count'=>12,'open_after_forwarding'=>8,'volume_count'=>11030,'approved_subsidy_amount'=>'12500.50']]]);
$ranges=array_column($batch,'range');$i=array_search("'Услуги по сектори'!A1:K200",$ranges,true);
$assert($i!==false&&count($batch[$i]['values'])===200&&$batch[$i]['values'][0][7]==='Препратени со пропратен акт'&&$batch[$i]['values'][1][8]===8&&$batch[$i]['values'][1][10]===12500.5,'Google Sheets gets a per-directorate sheet with forwarded cases, open after forwarding, visits and subsidies');
$assert(str_contains((string)file_get_contents(APP_ROOT.'/app/Service/MayorGoogleSheetsSync.php'),'ensureSheet($metadata,MayorGoogleSheetsMap::SERVICE_DIRECTORATES_SHEET,200,11)'),'the per-directorate sheet is created when missing');

$geodesy=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_10_28_003800_cadastre_geodesy_service.sql');
$assert(str_contains($geodesy,"(N'DKAD',N'DKAD_GEODESY',N'Геодезија',")&&!str_contains($geodesy,"(N'DKAD',N'DKAD_PROPERTY_TRANSFER'")&&str_contains($geodesy,"UPDATE dbo.citizen_services SET active=0,updated_at=SYSUTCDATETIME() WHERE code=N'DKAD_PROPERTY_TRANSFER' AND active=1"),'Cadastre: "Пренос на имот" is deactivated (history kept) and replaced by "Геодезија"');
$assert(str_contains($geodesy,"(N'subsidy',N'approved_amount',N'Одобрен износ (ден.)',N'amount',50)")&&str_contains($geodesy,"N'DHEALTH_VISITS_QKMF'"),'the catalog procedure keeps every other window');

$qshq=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_11_05_004300_administration_qshq_service.sql');
$assert(str_contains($qshq,"(N'ADM',N'ADM_QSHQ',N'ЦУГ – Центар за услуги на граѓаните',")&&str_contains($qshq,"N'Примени барања',N'Разгледани барања',N'Завршени барања',N'Во постапка',N'request')")&&str_contains($qshq,"(N'DKAD',N'DKAD_GEODESY',N'Геодезија',")&&!str_contains($qshq,"(N'DKAD',N'DKAD_PROPERTY_TRANSFER'")&&str_contains($qshq,'EXEC dbo.usp_seed_citizen_service_catalog;'),'Administrata gets QSHQ with the standard request fields; the rest of the catalog is unchanged');
$read=(string)file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');$write=(string)file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$assert(str_contains($read,'<details class="case-edit"><summary class="button button-secondary">Уреди го предметот</summary><form method="post" action="/cases/\'.$id.\'/update"')&&str_contains($write,'public function caseUpdate(int $id): never')&&str_contains($write,"Предметот е изменет од друг корисник")&&str_contains($write,"'case.updated','AdministrativeCase'"),'administrative cases can be edited (number, type, subject, description, priority, status) with version check and audit');
if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "citizen-service-windows-unit: OK\n";
