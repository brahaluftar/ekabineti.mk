<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;
use EKabineti\Core\Logger;
use EKabineti\Core\Request;
use EKabineti\Core\Translator;

final class Notifications
{
    public function __construct(private readonly Database $db, private readonly GraphMail $graph, private readonly Logger $logger) {}

    /**
     * Stores the in-app notification and queues the e-mail (sent by the scheduler, never inside the web request).
     * $dedupe=true is for scheduled reminders whose subject already contains the period (e.g. date); business events are always delivered.
     */
    public function notify(int $recipientId, string $template, string $subject, array $payload, bool $dedupe = false): void
    {
        $user=$this->db->one('SELECT locale,notification_email_mode FROM users WHERE id=:id',['id'=>$recipientId])?:[];
        $locale=(string)($user['locale']??'mk');$emailMode=in_array($user['notification_email_mode']??null,['immediate','daily','weekly','time_window'],true)?(string)$user['notification_email_mode']:'immediate';
        $subject=Translator::text($subject,$locale);if(isset($payload['body'])&&is_string($payload['body']))$payload['body']=Translator::text($payload['body'],$locale);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $exists = $dedupe && $this->db->scalar('SELECT TOP 1 1 FROM notifications WHERE recipient_id=:r AND channel=:c AND template=:t AND subject=:s', ['r' => $recipientId, 'c' => 'in_app', 't' => $template, 's' => $subject]);
        if (!$exists) $this->db->execute('INSERT INTO notifications (id,recipient_id,channel,template,subject,payload,status,retry_count,sent_at,created_at,updated_at) VALUES (:id,:r,:c,:t,:s,:p,:status,0,SYSUTCDATETIME(),SYSUTCDATETIME(),SYSUTCDATETIME())', ['id' => Request::uuid(), 'r' => $recipientId, 'c' => 'in_app', 't' => $template, 's' => $subject, 'p' => $encoded, 'status' => 'sent']);
        if (!$this->graph->enabled()) return;
        $emailId = Request::uuid();
        $exists = $dedupe && $this->db->scalar('SELECT TOP 1 1 FROM notifications WHERE recipient_id=:r AND channel=:c AND template=:t AND subject=:s', ['r' => $recipientId, 'c' => 'email', 't' => $template, 's' => $subject]);
        if (!$exists) {
            $this->db->execute('INSERT INTO notifications (id,recipient_id,channel,template,subject,payload,status,retry_count,created_at,updated_at) VALUES (:id,:r,:c,:t,:s,:p,:status,0,SYSUTCDATETIME(),SYSUTCDATETIME())', ['id' => $emailId, 'r' => $recipientId, 'c' => 'email', 't' => $template, 's' => $subject, 'p' => $encoded, 'status' => $emailMode==='immediate'?'pending':'deferred']);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_id=:u AND channel='in_app' AND read_at IS NULL", ['u' => $userId]);
    }

    public function dispatchDigests(): array
    {
        if(!$this->graph->enabled())return ['digests'=>0,'sent'=>0,'failed'=>0];
        $rows=$this->db->all("SELECT n.id,n.recipient_id,n.subject,n.payload,n.created_at,u.email,u.notification_email_mode,u.notification_digest_time,u.notification_digest_weekday,u.notification_window_start,u.notification_window_end,CONVERT(varchar(19),u.last_digest_sent_at,120) last_digest_sent_at FROM notifications n JOIN users u ON u.id=n.recipient_id WHERE n.channel='email' AND n.status='deferred' ORDER BY n.recipient_id,n.created_at");
        $groups=[];foreach($rows as$row)$groups[(int)$row['recipient_id']][]=$row;$digests=$sent=$failed=0;$now=new \DateTimeImmutable('now');
        foreach($groups as$recipientId=>$items){$first=$items[0];if(!$this->digestDue($first,$now))continue;$body=[];$ids=[];foreach($items as$item){$payload=json_decode((string)$item['payload'],true)?:[];$body[]=$item['subject']."\n".(string)($payload['body']??$item['subject']);$ids[]=$item['id'];}
            $digestId=Request::uuid();$this->db->execute('INSERT INTO notifications (id,recipient_id,channel,template,subject,payload,status,retry_count,created_at,updated_at) VALUES (:id,:recipient,:channel,:template,:subject,:payload,:status,0,SYSUTCDATETIME(),SYSUTCDATETIME())',['id'=>$digestId,'recipient'=>$recipientId,'channel'=>'email','template'=>'notification_digest','subject'=>'Сумарија на известувањата · е-Кабинет','payload'=>json_encode(['body'=>implode("\n\n",$body),'items'=>$ids],JSON_UNESCAPED_UNICODE),'status'=>'pending']);
            foreach($ids as$id)$this->db->execute('UPDATE notifications SET status=:status,updated_at=SYSUTCDATETIME() WHERE id=:id AND status=:deferred',['status'=>'batched','deferred'=>'deferred','id'=>$id]);$this->db->execute('UPDATE users SET last_digest_sent_at=SYSUTCDATETIME() WHERE id=:id',['id'=>$recipientId]);$digests++;if($this->process($digestId))$sent++;else$failed++;
        }
        return compact('digests','sent','failed');
    }

    private function digestDue(array $row, \DateTimeImmutable $now): bool
    {
        $time=$now->format('H:i');$digest=(string)($row['notification_digest_time']??'09:00');$mode=(string)($row['notification_email_mode']??'immediate');
        // One digest per day (per week for weekly mode): compare the last send in local time.
        $last=(string)($row['last_digest_sent_at']??'');
        if($last!==''){
            $lastLocal=(new \DateTimeImmutable($last,new \DateTimeZone('UTC')))->setTimezone($now->getTimezone());
            if($mode==='weekly'?$lastLocal>$now->modify('-6 days')->setTime(0,0):$lastLocal->format('Y-m-d')===$now->format('Y-m-d'))return false;
        }
        return match($mode){
            'daily'=>$time>=$digest,
            'weekly'=>$now->format('N')===(string)($row['notification_digest_weekday']??1)&&$time>=$digest,
            'time_window'=>$time>=(string)($row['notification_window_end']??'17:00'),
            default=>false,
        };
    }

    public function processPending(int $limit = 25): array
    {
        $rows = $this->db->all('SELECT TOP '.max(1, min(100, $limit)).' id FROM notifications WHERE channel=:c AND status IN (:pending,:failed) AND retry_count<5 ORDER BY created_at', ['c' => 'email', 'pending' => 'pending', 'failed' => 'failed']);
        $sent = $failed = 0;
        foreach ($rows as $row) { $this->process((string) $row['id']) ? $sent++ : $failed++; }
        return compact('sent', 'failed');
    }

    public function process(string $id): bool
    {
        $notification = $this->db->one('SELECT n.*,u.email FROM notifications n JOIN users u ON u.id=n.recipient_id WHERE n.id=:id', ['id' => $id]);
        if (!$notification || $notification['status'] === 'sent') return true;
        $this->db->execute('UPDATE notifications SET status=:s,updated_at=SYSUTCDATETIME() WHERE id=:id', ['s' => 'sending', 'id' => $id]);
        try {
            $payload = json_decode((string) $notification['payload'], true) ?: [];
            $requestId = $this->graph->send((string) $notification['email'], (string) $notification['subject'], (string) ($payload['body'] ?? $notification['subject']));
            $this->db->execute('UPDATE notifications SET status=:s,sent_at=SYSUTCDATETIME(),provider_request_id=:rid,last_error=NULL,updated_at=SYSUTCDATETIME() WHERE id=:id', ['s' => 'sent', 'rid' => $requestId, 'id' => $id]);
            return true;
        } catch (\Throwable $e) {
            $this->db->execute('UPDATE notifications SET status=:s,retry_count=retry_count+1,last_error=:e,updated_at=SYSUTCDATETIME() WHERE id=:id', ['s' => 'failed', 'e' => mb_substr($e->getMessage(), 0, 4000), 'id' => $id]);
            $this->logger->write('error', 'notification.graph_failed', ['notification_id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
