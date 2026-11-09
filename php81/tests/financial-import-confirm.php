<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
    $prefix='EKabineti\\';
    if (!str_starts_with($class,$prefix)) return;
    $file=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require $file;
});

$config=require __DIR__.'/local-config.php';
$db=new EKabineti\Core\Database($config['database']);
$request=new EKabineti\Core\Request();
$audit=new EKabineti\Service\Audit($db,$request);
$scope=new EKabineti\Security\Scope($db);
$actor=$db->one("SELECT TOP 1 u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND r.code='system_admin' AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.id");
$directorate=$db->one('SELECT TOP 1 id,code,name FROM directorates WHERE active=1 ORDER BY id');
if (!$actor || !$directorate) { fwrite(STDERR,"SKIP: system admin or directorate missing\n"); exit(2); }

$actorId=(int)$actor['id'];
$suffix=substr(str_replace('-','',EKabineti\Core\Request::uuid()),0,12);
$lineCode='V15-'.$suffix;
$commitmentReference='V15-ZOT-'.$suffix;
$sourceReference='V15-EXP-'.$suffix;
$capitalBatch=EKabineti\Core\Request::uuid();
$expenseBatch=EKabineti\Core\Request::uuid();
$capitalFile=$expenseFile=null;

try {
    $imports=new EKabineti\Service\ImportWorkflow($db,$scope,$audit,$actorId);
    $db->execute("INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,'capital_investment_budget','tests/confirm-capital.csv','preview',:actor,SYSUTCDATETIME(),SYSUTCDATETIME())",['id'=>$capitalBatch,'actor'=>$actorId]);
    $tmp=tempnam(sys_get_temp_dir(),'ek-confirm-capital-');$capitalFile=$tmp.'.csv';rename($tmp,$capitalFile);
    file_put_contents($capitalFile,"Шифра на проектот,Капитални,Буџет,Остаток,СЕКТОР\n{$lineCode},V15 confirm probe,1000,800,{$directorate['code']} - {$directorate['name']}\n");
    $preview=$imports->preview($capitalBatch,EKabineti\Service\ImportWorkflow::CAPITAL_BUDGET,$capitalFile,['fiscal_year'=>2096,'as_of_date'=>'2096-01-31','default_funding_source'=>'government_grant']);
    if ($preview['status']!=='ready') throw new RuntimeException('Capital preview was not ready: '.json_encode($preview));
    $batch=$db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$capitalBatch]);
    $capitalResult=$imports->confirm($batch);
    if (($capitalResult['created']??0)!==1) throw new RuntimeException('Capital line was not created.');

    $line=$db->one('SELECT bl.id,bl.directorate_id,bl.total_amount,bl.imported_available_amount,bc.base_year FROM budget_lines bl JOIN budget_versions bv ON bv.id=bl.budget_version_id JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bl.code=:code',['code'=>$lineCode]);
    if (!$line || (float)$line['total_amount']!==1000.0 || (float)$line['imported_available_amount']!==800.0) throw new RuntimeException('Capital line values were not persisted.');
    $allocationId=$db->insertId('INSERT INTO budget_allocations(budget_line_id,project_id,task_id,fiscal_year,period,planned_amount,current_amount,forecast_amount,created_at,updated_at) VALUES(:line,NULL,NULL,2096,0,800,800,800,SYSUTCDATETIME(),SYSUTCDATETIME())',['line'=>$line['id']]);
    $commitmentId=$db->insertId("INSERT INTO commitments(budget_allocation_id,reservation_id,reference,supplier,amount,committed_at,status,created_by,created_at,updated_at) VALUES(:allocation,NULL,:reference,'V15 Test Supplier',500,'2096-02-01','active',:actor,SYSUTCDATETIME(),SYSUTCDATETIME())",['allocation'=>$allocationId,'reference'=>$commitmentReference,'actor'=>$actorId]);

    $db->execute("INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,'budget_expenditures','tests/confirm-expense.csv','preview',:actor,SYSUTCDATETIME(),SYSUTCDATETIME())",['id'=>$expenseBatch,'actor'=>$actorId]);
    $tmp=tempnam(sys_get_temp_dir(),'ek-confirm-expense-');$expenseFile=$tmp.'.csv';rename($tmp,$expenseFile);
    file_put_contents($expenseFile,"external_row_key,directorate_code,budget_line_code,commitment_reference,source_reference,amount,accounting_date\nexpense-{$suffix},{$directorate['code']},{$lineCode},{$commitmentReference},{$sourceReference},125,2096-02-15\n");
    $preview=$imports->preview($expenseBatch,EKabineti\Service\ImportWorkflow::EXPENDITURES,$expenseFile);
    if ($preview['status']!=='ready') throw new RuntimeException('Expenditure preview was not ready: '.json_encode($preview));
    $batch=$db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$expenseBatch]);
    $expenseResult=$imports->confirm($batch);
    if (($expenseResult['created']??0)!==1) throw new RuntimeException('Expenditure was not created.');
    $expenditure=$db->one('SELECT id,commitment_id,budget_allocation_id,amount FROM expenditures WHERE source_reference=:reference',['reference'=>$sourceReference]);
    if (!$expenditure || (int)$expenditure['commitment_id']!==$commitmentId || (float)$expenditure['amount']!==125.0) throw new RuntimeException('Expenditure values were not persisted.');
    if ((int)$db->scalar("SELECT COUNT(*) FROM budget_transactions WHERE reference_type='App\\Models\\Expenditure' AND reference_id=:id AND type='expenditure'",['id'=>$expenditure['id']])!==1) throw new RuntimeException('Expenditure ledger transaction is missing.');
    if ((int)$db->scalar("SELECT COUNT(*) FROM audit_events WHERE correlation_id=:correlation AND action IN ('capital_budget.imported','expenditure.imported','import.posted')",['correlation'=>$request->correlationId])<4) throw new RuntimeException('Import audit events are missing.');
    echo "PASS: capital and expenditure batches confirmed atomically\n";
} finally {
    if (is_string($capitalFile) && is_file($capitalFile)) unlink($capitalFile);
    if (is_string($expenseFile) && is_file($expenseFile)) unlink($expenseFile);
    $db->transaction(function() use($db,$capitalBatch,$expenseBatch,$sourceReference,$commitmentReference,$lineCode,$request): void {
        $expense=$db->one('SELECT id FROM expenditures WHERE source_reference=:reference',['reference'=>$sourceReference]);
        if ($expense) $db->execute('DELETE FROM budget_transactions WHERE reference_type=:type AND reference_id=:id',['type'=>'App\\Models\\Expenditure','id'=>$expense['id']]);
        $db->execute('DELETE FROM expenditures WHERE source_reference=:reference',['reference'=>$sourceReference]);
        $db->execute('DELETE FROM commitments WHERE reference=:reference',['reference'=>$commitmentReference]);
        $line=$db->one('SELECT id,budget_version_id FROM budget_lines WHERE code=:code',['code'=>$lineCode]);
        if ($line) {
            $db->execute('DELETE FROM budget_allocations WHERE budget_line_id=:line',['line'=>$line['id']]);
            $db->execute('DELETE FROM budget_lines WHERE id=:id',['id'=>$line['id']]);
            $versionId=(int)$line['budget_version_id'];
            $cycleId=(int)$db->scalar('SELECT budget_cycle_id FROM budget_versions WHERE id=:id',['id'=>$versionId]);
            if (!(int)$db->scalar('SELECT COUNT(*) FROM budget_lines WHERE budget_version_id=:version',['version'=>$versionId])) $db->execute('DELETE FROM budget_versions WHERE id=:id',['id'=>$versionId]);
            if ($cycleId>0 && !(int)$db->scalar('SELECT COUNT(*) FROM budget_versions WHERE budget_cycle_id=:cycle',['cycle'=>$cycleId])) $db->execute('DELETE FROM budget_cycles WHERE id=:id',['id'=>$cycleId]);
        }
        $db->execute('DELETE FROM import_rows WHERE import_batch_id IN (:capital,:expense)',['capital'=>$capitalBatch,'expense'=>$expenseBatch]);
        $db->execute('DELETE FROM import_batches WHERE id IN (:capital,:expense)',['capital'=>$capitalBatch,'expense'=>$expenseBatch]);
        $db->execute('DELETE FROM audit_events WHERE correlation_id=:correlation',['correlation'=>$request->correlationId]);
    });
}

