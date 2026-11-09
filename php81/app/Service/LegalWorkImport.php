<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\HttpException;

/**
 * Import of Legal Office work reports: preview (plan stored in import_batches.summary) → confirmation.
 *
 * On confirmation every report is stored with its activities (a report already imported, same officer and period, is
 * replaced). Each case number is linked to the Legal Office case with the same court reference, or a case is created
 * (with its claimants and the municipality as respondent). Representations with a date become hearings (held when the
 * date is not after the report date, scheduled otherwise); a hearing already registered for that case and day is reused.
 */
final class LegalWorkImport
{
    public const TYPE = 'legal_work_report';
    private const CASE_TYPES = ['salary'=>'labor', 'property'=>'property', 'damages'=>'civil', 'administrative'=>'administrative', 'measure'=>'civil', 'other'=>'other'];

    public function __construct(private readonly Database $db, private readonly Audit $audit, private readonly int $userId) {}

    /**
     * Stores the plan of the reports read (LegalWorkReport::parse() for Word, ::fromSheet() for the Excel template):
     * reports to add or replace, cases to link or create, hearings to add.
     */
    public function preview(string $batchId, array $reports, string $fileName, ?int $responsibleUserId = null): array
    {
        $cases = $this->legalCases();
        $plan = ['type'=>self::TYPE, 'source_file_name'=>$fileName, 'responsible_user_id'=>$responsibleUserId, 'reports'=>[], 'new_cases'=>[], 'warnings'=>[], 'counts'=>['reports'=>0, 'activities'=>0, 'linked'=>0, 'new_cases'=>0, 'hearings'=>0, 'without_case'=>0, 'replaced'=>0]];
        foreach ($reports as $report) {
            if ($report['items'] === []) { $plan['warnings'] = array_merge($plan['warnings'], $report['warnings']); continue; }
            $key = self::reportKey($report);
            $existing = $this->db->scalar('SELECT id FROM legal_work_reports WHERE report_key=:key', ['key'=>$key]);
            $report['report_key'] = $key;
            $report['replaces'] = $existing !== false && $existing !== null ? (int) $existing : null;
            $plan['counts']['replaced'] += $report['replaces'] !== null ? 1 : 0;
            foreach ($report['items'] as &$item) {
                $caseKey = LegalWorkReport::caseKey($item['case_reference']);
                if ($caseKey === null) { $item['plan'] = 'none'; $plan['counts']['without_case']++; continue; }
                if (isset($cases[$caseKey])) { $item['plan'] = 'linked'; $item['case_id'] = $cases[$caseKey]['id']; $item['case_number'] = $cases[$caseKey]['case_number']; $plan['counts']['linked']++; }
                else {
                    $item['plan'] = 'created';
                    $new = &$plan['new_cases'][$caseKey];
                    $new ??= ['court_reference'=>$caseKey, 'parties'=>[], 'themes'=>[], 'domains'=>[], 'phase'=>null, 'subject'=>'', 'first_text'=>$item['item_text']];
                    foreach (array_filter(explode('; ', $item['parties'])) as $party) $new['parties'][self::partyName($party)] = true;
                    foreach ($item['themes'] as $theme) $new['themes'][$theme] = true;
                    $new['domains'][$item['domain']] = ($new['domains'][$item['domain']] ?? 0) + 1;
                    $new['phase'] ??= $item['phase'];
                    if ($new['subject'] === '' && $item['subject'] !== '') $new['subject'] = $item['subject'];
                    unset($new);
                }
                if ($item['section_code'] === 'representation' && $item['hearing_date'] !== null) $plan['counts']['hearings']++;
            }
            unset($item);
            $plan['counts']['reports']++;
            $plan['counts']['activities'] += count($report['items']);
            $plan['reports'][] = $report;
        }
        foreach ($plan['new_cases'] as &$new) { $new['parties'] = array_keys($new['parties']); $new['themes'] = array_keys($new['themes']); arsort($new['domains']); $new['domain'] = (string) array_key_first($new['domains']); }
        unset($new);
        $plan['counts']['new_cases'] = count($plan['new_cases']);
        $status = $plan['counts']['activities'] > 0 ? 'ready' : 'invalid';
        $this->db->execute('UPDATE import_batches SET status=:status,summary=:summary,updated_at=SYSUTCDATETIME() WHERE id=:id',
            ['status'=>$status, 'summary'=>json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'id'=>$batchId]);
        $this->audit->record($this->userId, 'legal_work_report.previewed', 'App\\Models\\ImportBatch', $batchId, [], $plan['counts'] + ['file'=>$fileName]);
        return $plan + ['status'=>$status];
    }

    /** Writes the plan of a ready preview in one transaction. */
    public function confirm(string $batchId): array
    {
        return $this->db->transaction(function () use ($batchId): array {
            $batch = $this->db->one('SELECT id,type,status,summary FROM import_batches WITH (UPDLOCK,HOLDLOCK) WHERE id=:id', ['id'=>$batchId]);
            if (!$batch || (string) $batch['type'] !== self::TYPE) throw new HttpException(404, 'Увозот не е пронајден.');
            if ((string) $batch['status'] !== 'ready') throw new HttpException(409, 'Овој увоз е потврден или откажан.');
            $plan = json_decode((string) $batch['summary'], true) ?: [];
            $legalDirectorate = (int) $this->db->scalar("SELECT id FROM directorates WHERE code='PRAV'");
            if ($legalDirectorate === 0) throw new HttpException(500, 'Секторот ПРАВНА недостасува.');
            $cases = $this->legalCases();
            $result = ['reports'=>0, 'activities'=>0, 'cases_created'=>0, 'cases_linked'=>0, 'hearings_created'=>0, 'hearings_reused'=>0, 'replaced'=>0];
            foreach ($plan['reports'] ?? [] as $report) {
                $officer = $this->officerUser((string) $report['officer_name']);
                $old = $this->db->scalar('SELECT id FROM legal_work_reports WHERE report_key=:key', ['key'=>$report['report_key']]);
                if ($old !== false && $old !== null) { $this->db->execute('DELETE FROM legal_work_activities WHERE report_id=:id', ['id'=>$old]); $this->db->execute('DELETE FROM legal_work_reports WHERE id=:id', ['id'=>$old]); $result['replaced']++; }
                $reportId = $this->db->insertId('INSERT INTO legal_work_reports(report_key,import_batch_id,officer_name,officer_title,officer_user_id,report_date,period_start,period_end,subject,source_file_name,activities,sections,warnings,created_by,created_at,updated_at) VALUES(:key,:batch,:name,:title,:officer,:date,:start,:end,:subject,:file,:activities,:sections,:warnings,:user,SYSUTCDATETIME(),SYSUTCDATETIME())', [
                    'key'=>$report['report_key'], 'batch'=>$batchId, 'name'=>(string) ($report['officer_name'] ?? 'Непознат'), 'title'=>$report['officer_title'], 'officer'=>$officer, 'date'=>$report['report_date'],
                    'start'=>$report['period_start'], 'end'=>$report['period_end'], 'subject'=>$report['subject'], 'file'=>mb_substr((string) ($plan['source_file_name'] ?? ''), 0, 255), 'activities'=>count($report['items']),
                    'sections'=>json_encode($report['sections'], JSON_UNESCAPED_UNICODE), 'warnings'=>$report['warnings'] ? json_encode($report['warnings'], JSON_UNESCAPED_UNICODE) : null, 'user'=>$this->userId,
                ]);
                $result['reports']++;
                foreach ($report['items'] as $item) {
                    $caseKey = LegalWorkReport::caseKey($item['case_reference']);
                    $caseId = null; $link = null; $hearingId = null;
                    if ($caseKey !== null) {
                        if (isset($cases[$caseKey])) { $caseId = $cases[$caseKey]['id']; $link = $cases[$caseKey]['created_now'] ?? false ? 'created' : 'linked'; if ($link === 'linked') $result['cases_linked']++; }
                        else {
                            $caseId = $this->createCase($plan['new_cases'][$caseKey] ?? ['court_reference'=>$caseKey, 'parties'=>[], 'themes'=>[], 'domain'=>$item['domain'], 'phase'=>$item['phase'], 'subject'=>$item['subject'], 'first_text'=>$item['item_text']], $report, isset($plan['responsible_user_id']) ? (int) $plan['responsible_user_id'] : $officer, $legalDirectorate);
                            $cases[$caseKey] = ['id'=>$caseId, 'case_number'=>'', 'created_now'=>true];
                            $link = 'created'; $result['cases_created']++;
                        }
                        if ($item['section_code'] === 'representation' && $item['hearing_date'] !== null) {
                            [$hearingId, $created] = $this->hearing($caseId, $item, $report, isset($plan['responsible_user_id']) ? (int) $plan['responsible_user_id'] : $officer);
                            $result[$created ? 'hearings_created' : 'hearings_reused']++;
                        }
                    }
                    $this->db->execute('INSERT INTO legal_work_activities(report_id,sort_order,section_code,section_label,item_text,activity,parties,case_reference,subject,themes,theme_codes,domain,hearing_date,case_id,case_link,hearing_id,created_at) VALUES(:report,:sort,:section,:label,:text,:activity,:parties,:case_ref,:subject,:themes,:codes,:domain,:hearing_date,:case_id,:link,:hearing,SYSUTCDATETIME())', [
                        'report'=>$reportId, 'sort'=>(int) $item['sort_order'], 'section'=>$item['section_code'], 'label'=>$item['section_label'], 'text'=>$item['item_text'], 'activity'=>$item['activity'],
                        'parties'=>$item['parties'] !== '' ? $item['parties'] : null, 'case_ref'=>$caseKey, 'subject'=>$item['subject'] !== '' ? $item['subject'] : null,
                        'themes'=>$item['themes'] ? implode(', ', $item['themes']) : null, 'codes'=>$item['theme_codes'] ? ','.implode(',', $item['theme_codes']).',' : null, 'domain'=>$item['domain'],
                        'hearing_date'=>$item['hearing_date'], 'case_id'=>$caseId, 'link'=>$link, 'hearing'=>$hearingId,
                    ]);
                    $result['activities']++;
                }
            }
            $plan['posted'] = $result;
            $this->db->execute("UPDATE import_batches SET status='posted',summary=:summary,confirmed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id", ['summary'=>json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id'=>$batchId]);
            $this->audit->record($this->userId, 'legal_work_report.posted', 'App\\Models\\ImportBatch', $batchId, [], $result);
            return $result;
        });
    }

    public function cancel(string $batchId): void
    {
        $affected = $this->db->execute("UPDATE import_batches SET status='cancelled',updated_at=SYSUTCDATETIME() WHERE id=:id AND type=:type AND status IN ('ready','invalid')", ['id'=>$batchId, 'type'=>self::TYPE]);
        if ($affected !== 1) throw new HttpException(409, 'Увозот е потврден или не постои.');
        $this->audit->record($this->userId, 'legal_work_report.cancelled', 'App\\Models\\ImportBatch', $batchId);
    }

    /** Officer + period: the identity of a report, so the same report imported again replaces the earlier copy. */
    public static function reportKey(array $report): string
    {
        $officer = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($report['officer_name'] ?? ''))));
        return mb_substr($officer.'|'.$report['period_start'].'|'.$report['period_end'], 0, 300);
    }

    /** „Ана Петровска и др.“ → „Ана Петровска“ (другите не се именувани). */
    public static function partyName(string $party): string
    {
        return trim((string) preg_replace('/\s+(?:и\s+др|etj)\.?$/iu', '', trim($party)));
    }

    /** Legal Office cases by normalised court reference. */
    private function legalCases(): array
    {
        $cases = [];
        foreach ($this->db->all('SELECT id,case_number,court_reference FROM administrative_cases WHERE is_legal=1 AND court_reference IS NOT NULL') as $row) {
            $key = LegalWorkReport::caseKey((string) $row['court_reference']);
            if ($key !== null && !isset($cases[$key])) $cases[$key] = ['id'=>(int) $row['id'], 'case_number'=>(string) $row['case_number']];
        }
        return $cases;
    }

    /** Legal Office staff (active, role in PRAV): id => name · @username. The default responsible is the head of the Legal Office. */
    public function legalStaff(): array
    {
        $staff = [];
        foreach ($this->db->all("SELECT DISTINCT u.id,u.name,u.username FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id JOIN directorates d ON d.id=urs.directorate_id WHERE u.status='active' AND d.code='PRAV' AND COALESCE(r.base_role_code,r.code) IN ('legal_office','legal_director','senior_legal_officer','legal_officer','legal_assistant','director') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.name") as $row) {
            $staff[(int) $row['id']] = $row['name'].' · @'.$row['username'];
        }
        return $staff;
    }

    public function defaultResponsible(): ?int
    {
        // Default: the active head of the Legal Office (base role legal_director in PRAV); otherwise chosen in the dialog.
        $id = $this->db->scalar("SELECT TOP 1 u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id JOIN directorates d ON d.id=urs.directorate_id WHERE u.status='active' AND d.code='PRAV' AND COALESCE(r.base_role_code,r.code)='legal_director' AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.id");
        return $id === false || $id === null ? null : (int) $id;
    }

    private function officerUser(string $name): ?int
    {
        if (trim($name) === '') return null;
        $id = $this->db->scalar("SELECT id FROM users WHERE LOWER(name)=LOWER(:name) AND status='active' ORDER BY id", ['name'=>trim($name)]);
        return $id === false || $id === null ? null : (int) $id;
    }

    private function createCase(array $new, array $report, ?int $officer, int $legalDirectorate): int
    {
        $responsible = $officer ?? $this->userId;
        $sequence = (int) $this->db->scalar('SELECT COALESCE(MAX(internal_sequence),0)+1 FROM administrative_cases WITH (TABLOCKX,HOLDLOCK)');
        $number = 'ZL-'.substr((string) ($report['report_date'] ?? date('Y-m-d')), 0, 4).'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $parties = array_values(array_filter((array) $new['parties']));
        $subject = mb_substr(trim(($parties ? implode(', ', $parties).' – ' : '').($new['themes'] ? implode(', ', (array) $new['themes']) : ((string) $new['subject'] ?: 'Судски предмет'))), 0, 250);
        $id = $this->db->insertId("INSERT INTO administrative_cases(case_uuid,internal_sequence,is_legal,case_number,case_type,subject,description,originating_directorate_id,responsible_user_id,status,priority,opened_at,created_by,created_at,updated_at,source_name,court_reference,authority_name,procedural_phase,assigned_at,currency,confidentiality) VALUES(NEWID(),:sequence,1,:number,:type,:subject,:description,:directorate,:responsible,'active','normal',:opened,:user,SYSUTCDATETIME(),SYSUTCDATETIME(),:source,:court,:authority,:phase,SYSUTCDATETIME(),'MKD','internal')", [
            'sequence'=>$sequence, 'number'=>$number, 'type'=>self::CASE_TYPES[$new['domain'] ?? 'other'] ?? 'other', 'subject'=>$subject,
            'description'=>'Создадено од работниот извештај на Правната служба ('.($report['officer_name'] ?? '').', '.($report['period_start'] ?? '').' – '.($report['period_end'] ?? '').'): '.mb_substr((string) $new['first_text'], 0, 1500),
            'directorate'=>$legalDirectorate, 'responsible'=>$responsible, 'opened'=>$report['report_date'] ?? date('Y-m-d'), 'user'=>$this->userId, 'source'=>'Неделен извештај на Правната служба',
            'court'=>$new['court_reference'], 'authority'=>self::authority((string) $new['court_reference']), 'phase'=>$new['phase'] ?? 'first_instance',
        ]);
        $this->db->execute("INSERT INTO case_directorates(case_id,directorate_id,relationship_type,responsible_user_id,status,assigned_by,assigned_at) VALUES(:case,:directorate,'OWNER',:responsible,'open',:user,SYSUTCDATETIME())", ['case'=>$id, 'directorate'=>$legalDirectorate, 'responsible'=>$responsible, 'user'=>$this->userId]);
        $this->db->execute('INSERT INTO legal_case_assignments(case_id,responsible_user_id,valid_from,assigned_by,reason) VALUES(:case,:responsible,SYSUTCDATETIME(),:user,:reason)', ['case'=>$id, 'responsible'=>$responsible, 'user'=>$this->userId, 'reason'=>'Создадено од увозот на работниот извештај']);
        foreach ($parties as $party) $this->db->execute("INSERT INTO legal_case_parties(case_id,party_name,party_role,created_by) VALUES(:case,:name,'claimant',:user)", ['case'=>$id, 'name'=>mb_substr($party, 0, 300), 'user'=>$this->userId]);
        $this->db->execute("INSERT INTO legal_case_parties(case_id,party_name,party_role,created_by) VALUES(:case,N'Општина Битола','respondent',:user)", ['case'=>$id, 'user'=>$this->userId]);
        $this->audit->record($this->userId, 'legal_case.created_from_work_report', 'AdministrativeCase', $id, [], ['case_number'=>$number, 'court_reference'=>$new['court_reference'], 'parties'=>$parties, 'officer'=>$report['officer_name'] ?? null]);
        return $id;
    }

    /** A hearing for the case on that day: the existing one, or a new one (held if not after the report date). */
    private function hearing(int $caseId, array $item, array $report, ?int $officer): array
    {
        $existing = $this->db->scalar('SELECT id FROM legal_case_hearings WHERE case_id=:case AND scheduled_at>=:from AND scheduled_at<:to', ['case'=>$caseId, 'from'=>$item['hearing_date'].' 00:00:00', 'to'=>date('Y-m-d', strtotime($item['hearing_date'].' +1 day')).' 00:00:00']);
        if ($existing !== false && $existing !== null) return [(int) $existing, false];
        $status = $report['report_date'] !== null && $item['hearing_date'] > $report['report_date'] ? 'scheduled' : 'held';
        $id = $this->db->insertId('INSERT INTO legal_case_hearings(case_id,procedure_id,scheduled_at,authority_name,venue_mode,responsible_user_id,status,notes,created_by) VALUES(:case,NULL,:at,:authority,NULL,:responsible,:status,:notes,:user)', [
            'case'=>$caseId, 'at'=>$item['hearing_date'].' 00:00:00', 'authority'=>self::authority((string) $item['case_reference']), 'responsible'=>$officer, 'status'=>$status,
            'notes'=>mb_substr('Од работниот извештај ('.($report['officer_name'] ?? '').'): '.$item['item_text'], 0, 2000), 'user'=>$this->userId,
        ]);
        $this->audit->record($this->userId, 'legal_case.hearing_from_work_report', 'LegalCaseHearing', $id, [], ['case_id'=>$caseId, 'date'=>$item['hearing_date'], 'status'=>$status]);
        return [$id, true];
    }

    /** Суд според бројот на предметот: П-, ПЛ1-, РО-, ПОУ-, ИЗВ- → Основен суд Битола; ГЖ-, КЖ-, РОЖ- → Апелационен суд Битола;
     *  РЕВ- → Врховен суд. Старите латинични броеви (C., AP., K.Dh.Sh., Rev) се препознаваат на ист начин. */
    public static function authority(string $reference): string
    {
        $prefix = preg_match('/^([^\d.\-\s\/]+)/u', trim($reference), $m) ? mb_strtoupper($m[1]) : '';
        return match (true) {
            in_array($prefix, ['AP', 'AC', 'CA', 'ГЖ', 'КЖ', 'РОЖ', 'ГЖН'], true) => 'Апелационен суд Битола',
            in_array($prefix, ['K', 'REV', 'РЕВ', 'РЕВ2'], true) => 'Врховен суд на Република Северна Македонија',
            default => 'Основен суд Битола',
        };
    }
}
