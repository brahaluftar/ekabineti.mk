<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$migration=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_10_22_002800_legal_office.sql');
$routes=(string)file_get_contents(APP_ROOT.'/app/Core/Application.php');
$write=(string)file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$read=(string)file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$scope=(string)file_get_contents(APP_ROOT.'/app/Security/Scope.php');
$report=(string)file_get_contents(APP_ROOT.'/app/Service/MayorDashboardReport.php');

$assert(str_contains($migration,"code=N'PRAV'")&&str_contains($migration,'legal_case_procedures')&&str_contains($migration,'legal_case_hearings')&&str_contains($migration,'legal_case_assignments'),'legal office migration provisions its organization and case structures');
$assert(str_contains($migration,"N'legal_director'")&&str_contains($migration,"N'senior_legal_officer'")&&str_contains($migration,"N'legal_assistant'"),'legal role profiles are provisioned');
$assert(str_contains($routes,"/legal-office/cases/(\\d+)/deadlines/(\\d+)/status")&&str_contains($routes,"/legal-office/users/(\\d+)/access"),'legal deadline and dynamic user access routes exist');
$assert(str_contains($write,'legalCaseData')&&str_contains($write,'assertLegalLinks')&&str_contains($write,'notifyLegalCaseBudgetOwner'),'legal records validate procurement and budget relationships');
$assert(str_contains($write,'user.password_reset_by_admin')&&str_contains($write,'$this->auth->revokeSessions($id)')&&str_contains((string)file_get_contents(APP_ROOT.'/app/Security/Auth.php'),'session_epoch'),'administrator password reset is audited and revokes sessions');
$storage=(string)file_get_contents(APP_ROOT.'/app/Service/PrivateAttachmentStorage.php');
$assert(str_contains($storage,"'court_document'=>")&&str_contains($storage,"'enforcement_decision'=>")&&str_contains($read,"documentFields(['court_document'")&&str_contains($read,'raporti-zyra-ligjore'),'legal documents (accepted by storage validation) and reports are exposed');
$assert(str_contains($read,'legalConfidentialityFilter')&&str_contains((string)file_get_contents(APP_ROOT.'/app/Service/EvidenceAccess.php'),"confidentiality==='restricted'"),'legal confidentiality is enforced in lists, reports and record access');
$assert(str_contains($scope,'canManageLegalOffice')&&str_contains($scope,"['legal_director','director']")&&str_contains($scope,"['director','directors_assistant']"),'the existing director profile is recognized only when scoped to PRAV');
$assert(str_contains((string)file_get_contents(APP_ROOT.'/app/Core/View.php'),"\$module['path'] !== '/legal-office' || \$this->scope->canAccessLegalOffice"),'the legal menu uses the same scoped authorization as its routes');
$assert(str_contains($report,'WHERE ac.is_legal=1'),'mayor legal sheet excludes unrelated administrative cases');

if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "legal-office-unit: OK\n";
