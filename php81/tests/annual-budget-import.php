<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'EKabineti\\')) require APP_ROOT.'/app/'.str_replace('\\','/',substr($class,10)).'.php';
});
$config = require __DIR__.'/local-config.php';
$db = new EKabineti\Core\Database($config['database']);
if (!str_contains(strtolower((string) $db->scalar('SELECT DB_NAME()')), 'test')) throw new RuntimeException('Test database required.');
$scope = new EKabineti\Security\Scope($db);
$request = new EKabineti\Core\Request();
$audit = new EKabineti\Service\Audit($db, $request);
$actor = (int) $db->scalar("SELECT TOP 1 urs.user_id FROM user_role_scopes urs JOIN roles r ON r.id=urs.role_id WHERE r.code='system_admin'");
$workflow = new EKabineti\Service\ImportWorkflow($db,$scope,$audit,$actor);
$path = $argv[1] ?? '';
if (!is_file($path)) throw new RuntimeException('Pass the source XLSX path.');
if ($db->scalar('SELECT id FROM budget_cycles WHERE base_year=2095')) throw new RuntimeException('Reserved test year already exists.');
$batches=[]; $createdDirectorates=[];
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$preview = static function(string $file) use ($db,$workflow,$actor,&$batches): array {
    $id = EKabineti\Core\Request::uuid();
    $batches[]=$id;
    $db->execute("INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,'annual_budget','tests/annual-budget.xlsx','preview',:actor,SYSUTCDATETIME(),SYSUTCDATETIME())", ['id'=>$id,'actor'=>$actor]);
    $result = $workflow->preview($id,'annual_budget',$file,['fiscal_year'=>2095]);
    return [$id,$result];
};
$baseTemp=tempnam(sys_get_temp_dir(),'annual-');$temp=$baseTemp.'.csv';rename($baseTemp,$temp);
try {
    $sourceRows = iterator_to_array((new EKabineti\Service\SpreadsheetReader())->rows($path));
    foreach (array_slice($sourceRows,1) as $row) {
        if (!$db->scalar('SELECT id FROM directorates WHERE code=:code',['code'=>$row[2]])) {
            $createdDirectorates[]=$row[2];
            $db->execute('INSERT INTO directorates(code,name,active,created_at,updated_at) VALUES(:code,:name,1,SYSUTCDATETIME(),SYSUTCDATETIME())',['code'=>$row[2],'name'=>$row[3]]);
        }
    }
    [$id,$result] = $preview($path);
    if ($result['status'] !== 'ready') {
        echo json_encode($db->all("SELECT TOP 10 external_row_key,errors FROM import_rows WHERE import_batch_id=:id AND status='invalid'",['id'=>$id]),JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
    $check($result['status']==='ready' && $result['valid']===319, 'Source workbook must validate all 319 rows.');
    $post = $workflow->confirm($db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$id]));
    $check($post['created']===319, '319 lines created.');
    $sum = $db->one('SELECT COUNT(*) n,SUM(total_amount) total,SUM(loan_financing) loans,COUNT(DISTINCT budget_category) categories FROM budget_lines WHERE budget_version_id IN (SELECT bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bc.base_year=2095)');
    $check((int)$sum['n']===319 && abs((float)$sum['total']-(float)$result['totals']['budgeted'])<0.0001, 'Stored total reconciles.');
    $check((float)$sum['loans']===2000000.0 && (int)$sum['categories']===5, 'Borrowing and all categories preserved.');
    [$second,$again] = $preview($path);
    $post = $workflow->confirm($db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$second]));
    $check($post['created']===0 && $post['updated']===319, 'Reimport updates without duplicates.');
    try { $workflow->confirm($db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$second])); $check(false,'Repeated confirmation accepted.'); }
    catch (EKabineti\Core\HttpException $e) { $checks++; }
    $rows = iterator_to_array((new EKabineti\Service\SpreadsheetReader())->rows($path));
    $write = static function(array $rows) use ($temp): void {$h=fopen($temp,'wb');foreach($rows as $row)fputcsv($h,$row);fclose($h);};
    $bad=$rows[1];$bad[13]='1';$write([$rows[0],$bad]);[, $r]=$preview($temp);$check($r['status']==='invalid','Mismatched sources rejected.');
    $bad=$rows[1];$bad[2]='UNKNOWN';$write([$rows[0],$bad]);[, $r]=$preview($temp);$check($r['status']==='invalid','Unknown directorate rejected.');
    $bad=$rows[1];$bad[4]='Unknown category';$write([$rows[0],$bad]);[, $r]=$preview($temp);$check($r['status']==='invalid','Unknown category rejected.');
    $write([$rows[0],$rows[1],$rows[1]]);[, $r]=$preview($temp);$check($r['status']==='invalid','Duplicate line rejected.');
    $write([$rows[0],array_fill(0,14,'')]);[, $r]=$preview($temp);$check($r['status']==='invalid','Empty data rejected.');
    $write([$rows[0],$rows[1]]);
    [$scopeBatch] = $preview($temp);
    $payload=json_decode($db->scalar('SELECT payload FROM import_rows WHERE import_batch_id=:id',['id'=>$scopeBatch]),true);
    $validator=new ReflectionMethod(EKabineti\Service\ImportWorkflow::class,'validateAnnualBudget');
    $validator->setAccessible(true);
    $errors=$validator->invoke(new EKabineti\Service\ImportWorkflow($db,$scope,$audit,0),$payload);
    $check(in_array('financial_scope_required',array_column($errors,'code'),true),'Missing financial scope rejected.');
    try {
        (new EKabineti\Service\ImportWorkflow($db,$scope,$audit,0))->confirm($db->one('SELECT * FROM import_batches WHERE id=:id',['id'=>$scopeBatch]));
        $check(false,'Confirmation must recheck financial scope.');
    } catch (EKabineti\Core\HttpException $e) {
        $check($db->scalar('SELECT status FROM import_batches WHERE id=:id',['id'=>$scopeBatch])==='ready','Failed confirmation rolls back batch state.');
    }
    echo json_encode(['checks'=>$checks,'source_rows'=>319,'total'=>$sum['total'],'loan_financing'=>$sum['loans'],'cleanup'=>true]).PHP_EOL;
} finally {
    $db->transaction(function() use ($db,$batches,$createdDirectorates,$request): void {
        $db->execute('DELETE FROM budget_lines WHERE budget_version_id IN (SELECT bv.id FROM budget_versions bv JOIN budget_cycles bc ON bc.id=bv.budget_cycle_id WHERE bc.base_year=2095)');
        $db->execute('DELETE FROM budget_versions WHERE budget_cycle_id IN (SELECT id FROM budget_cycles WHERE base_year=2095)');
        $db->execute('DELETE FROM budget_cycles WHERE base_year=2095');
        foreach($batches as $batch) {$db->execute('DELETE FROM import_rows WHERE import_batch_id=:id',['id'=>$batch]);$db->execute('DELETE FROM import_batches WHERE id=:id',['id'=>$batch]);}
        foreach($createdDirectorates as $code) $db->execute('DELETE FROM directorates WHERE code=:code',['code'=>$code]);
        $db->execute('DELETE FROM audit_events WHERE correlation_id=:id',['id'=>$request->correlationId]);
    });
    if (is_file($temp)) unlink($temp);
}
