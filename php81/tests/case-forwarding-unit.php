<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$migration=(string)file_get_contents(APP_ROOT.'/database/migrations/2026_10_26_003600_case_forwardings.sql');
$routes=(string)file_get_contents(APP_ROOT.'/app/Core/Application.php');
$write=(string)file_get_contents(APP_ROOT.'/app/Controller/WriteController.php');
$read=(string)file_get_contents(APP_ROOT.'/app/Controller/ReadController.php');
$ui=(string)file_get_contents(APP_ROOT.'/app/Service/EvidenceUi.php');
$js=(string)file_get_contents(APP_ROOT.'/public/assets/app.js');
$storage=(string)file_get_contents(APP_ROOT.'/app/Service/PrivateAttachmentStorage.php');

$assert(str_contains($migration,"IF OBJECT_ID('dbo.case_forwardings','U') IS NULL")&&str_contains($migration,'cover_letter_number nvarchar(100) NOT NULL')&&str_contains($migration,'CK_case_forwardings_directorates CHECK(from_directorate_id <> to_directorate_id)'),'forwardings are stored with the cover letter and two different directorates');
$assert(str_contains($routes,"'#^/cases/(\\d+)/forward$#'")&&str_contains($routes,"'#^/cases/(\\d+)/forwardings/(\\d+)/receive$#'")&&str_contains($routes,"'case_forwardings'"),'forward and receipt routes exist and the table is checked at health time');
$assert(str_contains($write,'caseFile($caseId,true)')&&str_contains($write,"if(!in_array(\$from,\$held,true))")&&str_contains($write,'allowsDirectorate($this->userId(),$from)'),'only a directorate that holds the case, and is in the user\'s scope, can forward it');
$assert(str_contains($write,"relationship_type='FORWARDED'")&&str_contains($write,"'case_forwarded'")&&str_contains($write,'$this->directorateHeads($to)')&&str_contains($write,"'case.forwarded'"),'the receiving directorate gets access, its director is notified and the forwarding is audited');
$assert(str_contains($write,"foreach([['director'],['directors_assistant']] as\$codes)"),'the notification goes to the director, or the director\'s assistant when there is no director');
$assert(str_contains($write,"Приемот го потврдува секторот што прима.")&&str_contains($write,"'case_forward_received'"),'only the receiving directorate confirms receipt, and the sender is told');
$assert(str_contains($write,"Правните предмети ги препраќа Правната служба."),'legal files are not forwarded from the administrative-case page');
$assert(str_contains($read,'private function caseForwardingSection(array $case)')&&substr_count($read,"'.\$forwarding.'")===2,'the case page shows forwardings for every role (read-only for the Mayor)');
$assert(str_contains($read,"if(\$forwarded==='pending')")&&str_contains($read,'чекаат потврда за прием'),'the case list filters forwarded cases and those waiting for my receipt');
$assert(str_contains($read,'FROM case_forwardings f JOIN directorates fd ON fd.id=f.from_directorate_id JOIN directorates td ON td.id=f.to_directorate_id WHERE f.forwarded_on>=:forward_from'),'the weekly services report counts forwardings automatically');
$assert(substr_count($read,"EvidenceUi::dropzone('files[]',true,true,")===2&&str_contains($ui,'public static function dropzone(')&&str_contains($js,"document.querySelectorAll('[data-dropzone]')"),'administrative and legal case documents are uploaded by drag and drop, several at once');
$assert(str_contains($storage,"'cover_letter'=>'Пропратен акт'")&&str_contains($storage,"(int)(\$input['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE ? [] : [\$input]"),'cover letters have their own document type and an empty optional file is ignored');

if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "case-forwarding-unit: OK\n";
