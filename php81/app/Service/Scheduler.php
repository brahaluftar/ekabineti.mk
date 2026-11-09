<?php

declare(strict_types=1);

namespace EKabineti\Service;

use DateTimeImmutable;
use EKabineti\Core\Database;
use EKabineti\Core\Logger;

final class Scheduler
{
    public function __construct(
        private readonly Database $db,
        private readonly Notifications $notifications,
        private readonly Logger $logger,
        private readonly string $storagePath,
        private readonly ?string $clamavBinary = null,
        private readonly array $config = [],
    ) {
    }

    public function run(): array
    {
        $lock = fopen($this->storagePath.'/scheduler.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status'=>'busy', 'message'=>'Scheduler is already running.'];
        }

        try {
            $this->heartbeat();
            $campaigns = $this->reportingReminders();
            $risks = $this->criticalRiskReminders();
            $caseTasks = $this->caseTaskReminders();
            $digests = $this->notifications->dispatchDigests();
            $mail = $this->notifications->processPending(25);
            // Scanning is slow (clamscan loads signatures per file); the web-triggered scheduler leaves it to bin/scan-documents.php (cron).
            $documents = PHP_SAPI === 'cli' ? (new DocumentScanner($this->db, $this->logger, $this->storagePath.'/private', $this->clamavBinary))->run(50) : ['status' => 'delegated_to_cron'];
            $throttle = (new LoginThrottle($this->db))->purgeExpired();
            // Revenue reports whose background reading did not start or was interrupted.
            try { $revenues = RevenueImport::processQueue($this->db, new Audit($this->db, new \EKabineti\Core\Request()), 1); }
            catch (\Throwable $exception) { $revenues = ['error'=>get_class($exception)]; $this->logger->write('error', 'scheduler.revenue_imports_failed', ['error'=>$exception->getMessage()]); }
            try { $briefing = $this->weeklyMayorBriefing(); }
            catch (\Throwable $exception) { $briefing = ['error'=>get_class($exception)]; $this->logger->write('error', 'scheduler.mayor_briefing_failed', ['error'=>$exception->getMessage()]); }
            try { $directorateBriefings = $this->weeklyDirectorateBriefings(); }
            catch (\Throwable $exception) { $directorateBriefings = ['error'=>get_class($exception)]; $this->logger->write('error', 'scheduler.directorate_briefings_failed', ['error'=>$exception->getMessage()]); }
            $result = ['status'=>'ok', 'mayor_briefing'=>$briefing, 'directorate_briefings'=>$directorateBriefings, 'revenue_imports'=>$revenues, 'reporting_campaigns'=>$campaigns, 'critical_risks'=>$risks, 'case_task_reminders'=>$caseTasks, 'notification_digests'=>$digests, 'documents'=>$documents, 'expired_throttle_rows'=>$throttle] + $mail;
            $this->logger->write('info', 'scheduler.completed', $result);
            return $result;
        } catch (\Throwable $exception) {
            $this->logger->write('error', 'scheduler.failed', [
                'exception'=>get_class($exception),
                'error'=>$exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function heartbeat(): void
    {
        $this->db->execute(
            "MERGE cache AS target
             USING (SELECT :key AS [key], :value AS [value], :expiration AS [expiration]) AS source
             ON target.[key]=source.[key]
             WHEN MATCHED THEN UPDATE SET value=source.[value],expiration=source.[expiration]
             WHEN NOT MATCHED THEN INSERT ([key],value,expiration)
             VALUES (source.[key],source.[value],source.[expiration]);",
            ['key'=>'scheduler:last_heartbeat', 'value'=>gmdate('c'), 'expiration'=>time()+600]
        );
    }

    /**
     * Weekly briefing of the Mayor: generated once a week, from Monday 07:00 (local time), when the week has none yet;
     * the Mayor, the Mayor's administrators and the deputy mayors are notified.
     */
    private function weeklyMayorBriefing(?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new DateTimeImmutable('now');
        $week = MayorBriefing::weekStart($now->format('Y-m-d'));
        if ((int) $now->format('N') === 1 && (int) $now->format('G') < 7) return null;
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM mayor_briefings WHERE week_start=:week AND directorate_id IS NULL', ['week'=>$week]) > 0) return null;
        $client = $this->config !== [] ? new OpenAIResponsesClient(OpenAIConfig::fromApplication($this->config)) : null;
        $id = (new MayorBriefing($this->db, $client))->generate($week, null);
        $recipients = $this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin','deputy_mayor') AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())");
        foreach ($recipients as $row) $this->notifications->notify((int) $row['id'], 'mayor_briefing', 'Неделен брифинг · '.MayorBriefing::date($week), ['body'=>'Неделниот брифинг на Градоначалникот за неделата што почнува на '.MayorBriefing::date($week).' е подготвен.', 'url'=>'/mayor-briefing?id='.$id], true);
        return $id;
    }

    /**
     * Weekly briefing of each active directorate with projects, from Monday 07:00: at most $limit per run (the AI call
     * takes time), the next runs continue. The directors and their assistants are notified.
     */
    private function weeklyDirectorateBriefings(?\DateTimeImmutable $now = null, int $limit = 3): array
    {
        $now ??= new DateTimeImmutable('now');
        if ((int) $now->format('N') === 1 && (int) $now->format('G') < 7) return [];
        $week = MayorBriefing::weekStart($now->format('Y-m-d'));
        $due = $this->db->all("SELECT TOP {$limit} d.id,d.code,d.name FROM directorates d WHERE d.active=1 AND EXISTS (SELECT 1 FROM projects p WHERE p.directorate_id=d.id AND p.status='active') AND NOT EXISTS (SELECT 1 FROM mayor_briefings b WHERE b.directorate_id=d.id AND b.week_start=:week) ORDER BY d.code", ['week'=>$week]);
        if ($due === []) return [];
        $client = $this->config !== [] ? new OpenAIResponsesClient(OpenAIConfig::fromApplication($this->config)) : null;
        $made = [];
        foreach ($due as $d) {
            $id = (new MayorBriefing($this->db, $client))->generate($week, null, (int) $d['id']);
            $made[] = $id;
            $recipients = $this->db->all("SELECT DISTINCT u.id FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id WHERE u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('director','directors_assistant') AND urs.directorate_id=:dir AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())", ['dir'=>$d['id']]);
            foreach ($recipients as $row) $this->notifications->notify((int) $row['id'], 'directorate_briefing', 'Неделен брифинг на секторот · '.$d['code'].' · '.MayorBriefing::date($week), ['body'=>'Неделниот брифинг на '.$d['name'].' за неделата што почнува на '.MayorBriefing::date($week).' е подготвен.', 'url'=>'/directorate-briefing?id='.$id], true);
        }
        return $made;
    }

    private function caseTaskReminders(): int
    {
        $tasks=$this->db->all("SELECT TOP 200 t.id,t.case_id,t.title,t.assigned_to,t.due_at FROM case_tasks t JOIN users u ON u.id=t.assigned_to WHERE u.status='active' AND t.status<>'completed' AND t.due_at<=DATEADD(day,1,CONVERT(date,SYSUTCDATETIME())) ORDER BY t.due_at");
        $count=0;
        foreach($tasks as $t){
            try{(new EvidenceAccess($this->db,new \EKabineti\Security\Scope($this->db),(int)$t['assigned_to']))->caseFile((int)$t['case_id']);}catch(\EKabineti\Core\HttpException $e){continue;}
            $this->notifications->notify((int)$t['assigned_to'],'case_task_due','Рок на задачата #'.$t['id'].' · '.gmdate('Y-m-d'),['body'=>$t['title'].' · Рок '.$t['due_at'],'case_id'=>$t['case_id'],'task_id'=>$t['id']],true);$count++;
        }
        return $count;
    }

    private function reportingReminders(): int
    {
        $today = new DateTimeImmutable('today');
        $campaigns = $this->db->all("SELECT * FROM reporting_campaigns WHERE status='active' AND due_at>=SYSUTCDATETIME()");
        $processed = 0;
        foreach ($campaigns as $campaign) {
            $due = new DateTimeImmutable((string) $campaign['due_at']);
            $days = (int) $today->diff($due->setTime(0, 0))->format('%r%a');
            $configured = json_decode((string) ($campaign['reminder_days'] ?: '[7,3,1]'), true);
            if (!is_array($configured) || !in_array($days, array_map('intval', $configured), true)) {
                continue;
            }
            $audience = json_decode((string) $campaign['audience'], true);
            $roles = is_array($audience) ? ($audience['roles'] ?? []) : [];
            if (!is_array($roles) || $roles === []) {
                continue;
            }
            $marks = [];
            $params = ['active'=>'active'];
            foreach (array_values($roles) as $index=>$role) {
                $marks[] = ':role'.$index;
                $params['role'.$index] = (string) $role;
            }
            $users = $this->db->all(
                "SELECT DISTINCT u.id FROM users u
                 JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id
                 WHERE u.status=:active AND COALESCE(r.base_role_code,r.code) IN (".implode(',', $marks).")
                 AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME())
                 AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())",
                $params
            );
            foreach ($users as $user) {
                $this->notifications->notify(
                    (int) $user['id'],
                    'reporting_deadline',
                    $campaign['title'].' · '.$days.' дена',
                    ['campaign_id'=>(int)$campaign['id'], 'days_remaining'=>$days, 'body'=>'Рокот за известување е по '.$days.' дена.'],
                    true
                );
            }
            $processed++;
        }
        return $processed;
    }

    private function criticalRiskReminders(): int
    {
        $leaders = $this->db->all(
            "SELECT DISTINCT u.id FROM users u
             JOIN user_role_scopes urs ON urs.user_id=u.id JOIN roles r ON r.id=urs.role_id
             WHERE u.status='active' AND COALESCE(r.base_role_code,r.code) IN ('mayor','mayor_admin')
             AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME())
             AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME())"
        );
        $risks = $this->db->all("SELECT id,description,inherent_score FROM risks WHERE status='open' AND inherent_score>=15");
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        foreach ($risks as $risk) {
            foreach ($leaders as $user) {
                $this->notifications->notify(
                    (int) $user['id'],
                    'critical_risk_daily',
                    'Критичен ризик #'.$risk['id'].' · '.$today,
                    ['risk_id'=>(int)$risk['id'], 'score'=>(int)$risk['inherent_score'], 'body'=>$risk['description'].' (резултат: '.$risk['inherent_score'].')'],
                    true
                );
            }
        }
        return count($risks);
    }
}
