<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Service\MayorBriefing;
use EKabineti\Service\MayorMeetingBrief;
use EKabineti\Service\OpenAIConfig;
use EKabineti\Service\OpenAIResponsesClient;

/** Weekly briefing of the Mayor (/mayor-briefing) and meeting preparation on the agenda (/mayor-agenda/{id}/prepare). */
final class MayorBriefingController extends Controller
{
    private function view(): View { return new View($this->auth, $this->scope); }
    private function client(): OpenAIResponsesClient { return new OpenAIResponsesClient(OpenAIConfig::fromApplication($this->config)); }
    private function requireBriefing(): void { if (!$this->scope->canAccessMayorBriefing($this->userId())) throw new HttpException(403, 'Неделниот брифинг е само за Градоначалникот и неговиот кабинет.'); }

    /** GET /mayor-briefing?week=YYYY-MM-DD&id=N */
    public function index(): never
    {
        $this->requireBriefing();
        $week = MayorBriefing::weekStart($this->weekInput());
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $briefing = $id ? $this->db->one('SELECT b.*,u.name generated_by_name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.id=:id AND b.directorate_id IS NULL', ['id'=>$id])
            : $this->db->one('SELECT TOP 1 b.*,u.name generated_by_name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.week_start=:week AND b.directorate_id IS NULL ORDER BY b.id DESC', ['week'=>$week]);
        if ($briefing) $week = substr((string) $briefing['week_start'], 0, 10);
        $prev = (new \DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d'); $next = (new \DateTimeImmutable($week))->modify('+7 days')->format('Y-m-d');
        $body = '<div class="page-actions"><a class="button button-secondary" href="/mayor-briefing?week='.$prev.'">← Претходна недела</a>'
            .($week < MayorBriefing::weekStart(date('Y-m-d')) ? '<a class="button button-secondary" href="/mayor-briefing?week='.$next.'">Следна недела →</a>' : '')
            .'<form method="post" action="/mayor-briefing/generate">'.$this->view()->csrf().'<input type="hidden" name="week" value="'.$week.'"><button class="primary">'.($briefing ? 'Повторно генерирај брифинг' : 'Генерирај брифинг').'</button></form>'
            .'<a class="button button-secondary" href="/mayor-agenda">Агенда на Градоначалникот</a></div>';
        $body .= '<p class="muted">Недела што почнува на <strong>'.View::e(MayorBriefing::date($week)).'</strong>: што се случи во изминатите 7 дена и што следува во наредните 7 дена. Фактите се читаат од апликацијата; AI ја пишува само сумаријата и секоја нејзина точка ги покажува фактите на кои се темели. Имињата на граѓаните и странките не се испраќаат до AI.</p>';
        if (!$briefing) {
            $body .= '<section class="card"><p>Нема брифинг за оваа недела. Брифингот се генерира автоматски секој понеделник или со копчето „Генерирај брифинг“.</p></section>';
        } else {
            $body .= $this->briefingHtml($briefing);
        }
        $history = '';
        foreach ($this->db->all('SELECT TOP 12 b.id,b.week_start,b.created_at,b.result_json,u.name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.directorate_id IS NULL ORDER BY b.week_start DESC,b.id DESC') as $h) {
            $history .= '<tr><td><a href="/mayor-briefing?id='.(int) $h['id'].'">'.View::e(MayorBriefing::date((string) $h['week_start'])).'</a></td><td>'.View::e($this->localTime((string) $h['created_at'])).'</td><td>'.View::e($h['name'] ?? 'Автоматски').'</td><td>'.($h['result_json'] ? 'Со AI сумарија' : 'Само факти').'</td></tr>';
        }
        $body .= '<section class="card"><h2>Претходни брифинзи</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Недела</th><th>Генерирано на</th><th>Од</th><th>Вид</th></tr></thead><tbody>'.($history ?: '<tr><td colspan="4">—</td></tr>').'</tbody></table></div></section>';
        $this->view()->render('Неделен брифинг на Градоначалникот', $body);
    }

    /** POST /mayor-briefing/generate */
    public function generate(): never
    {
        $this->requireBriefing();
        $week = MayorBriefing::weekStart($this->weekInput());
        @set_time_limit(180);
        $id = (new MayorBriefing($this->db, $this->client()))->generate($week, $this->userId());
        $this->audit->record($this->userId(), 'mayor_briefing.generated', 'MayorBriefing', $id, [], ['week_start'=>$week]);
        $error = $this->db->scalar('SELECT ai_error FROM mayor_briefings WHERE id=:id', ['id'=>$id]);
        $this->redirect('/mayor-briefing?id='.$id, $error ? 'Брифингот е генериран со фактите; AI сумаријата не успеа.' : 'Брифингот е генериран.');
    }

    /** GET /directorate-briefing?directorate_id=&week=&id= : weekly briefing of a directorate. */
    public function directorateIndex(): never
    {
        $allowed = $this->scope->directorateBriefingIds($this->userId());
        if ($allowed === []) throw new HttpException(403, 'Брифингот на секторот е за раководителите на сектори, нивните асистенти и кабинетот на Градоначалникот.');
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $briefing = $id ? $this->db->one('SELECT b.*,u.name generated_by_name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.id=:id AND b.directorate_id IS NOT NULL', ['id'=>$id]) : null;
        $directorates = $this->db->all('SELECT id,code,name FROM directorates WHERE active=1 ORDER BY name');
        if ($allowed !== null) $directorates = array_values(array_filter($directorates, static fn(array $d): bool => in_array((int) $d['id'], $allowed, true)));
        if ($directorates === []) throw new HttpException(403, 'Немате активен сектор.');
        $directorateId = $briefing ? (int) $briefing['directorate_id'] : (int) ($_GET['directorate_id'] ?? $directorates[0]['id']);
        if ($allowed !== null && !in_array($directorateId, $allowed, true)) throw new HttpException(403, 'Немате пристап до брифингот на овој сектор.');
        $week = $briefing ? substr((string) $briefing['week_start'], 0, 10) : MayorBriefing::weekStart($this->weekInput());
        $briefing ??= $this->db->one('SELECT TOP 1 b.*,u.name generated_by_name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.directorate_id=:dir AND b.week_start=:week ORDER BY b.id DESC', ['dir'=>$directorateId, 'week'=>$week]);
        $options = '';
        foreach ($directorates as $d) $options .= '<option value="'.(int) $d['id'].'"'.((int) $d['id'] === $directorateId ? ' selected' : '').'>'.View::e($d['code'].' · '.$d['name']).'</option>';
        $prev = (new \DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d'); $next = (new \DateTimeImmutable($week))->modify('+7 days')->format('Y-m-d');
        $body = '<form method="get" action="/directorate-briefing" class="inline-actions"><label>Сектор<select name="directorate_id">'.$options.'</select></label><label>Недела<input type="date" name="week" value="'.$week.'"></label><button class="button button-secondary">Прикажи</button></form>'
            .'<div class="page-actions"><a class="button button-secondary" href="/directorate-briefing?directorate_id='.$directorateId.'&week='.$prev.'">← Претходна недела</a>'
            .($week < MayorBriefing::weekStart(date('Y-m-d')) ? '<a class="button button-secondary" href="/directorate-briefing?directorate_id='.$directorateId.'&week='.$next.'">Следна недела →</a>' : '')
            .'<form method="post" action="/directorate-briefing/generate">'.$this->view()->csrf().'<input type="hidden" name="week" value="'.$week.'"><input type="hidden" name="directorate_id" value="'.$directorateId.'"><button class="primary">'.($briefing ? 'Повторно генерирај брифинг' : 'Генерирај брифинг').'</button></form></div>';
        $body .= '<p class="muted">Недела што почнува на <strong>'.View::e(MayorBriefing::date($week)).'</strong>: проектите (со доцнење, со задоцнети задачи, без напредок во последните три недели, без дефинирани задачи), ризиците, буџетот, услугите за граѓаните, препратените предмети и агендата на Градоначалникот за секторот. Брифингот се генерира автоматски секој понеделник.</p>';
        $body .= $briefing ? $this->briefingHtml($briefing) : '<section class="card"><p>Нема брифинг за оваа недела. Користете „Генерирај брифинг“.</p></section>';
        $history = '';
        foreach ($this->db->all('SELECT TOP 12 b.id,b.week_start,b.created_at,b.result_json,u.name FROM mayor_briefings b LEFT JOIN users u ON u.id=b.generated_by WHERE b.directorate_id=:dir ORDER BY b.week_start DESC,b.id DESC', ['dir'=>$directorateId]) as $h) {
            $history .= '<tr><td><a href="/directorate-briefing?id='.(int) $h['id'].'">'.View::e(MayorBriefing::date((string) $h['week_start'])).'</a></td><td>'.View::e($this->localTime((string) $h['created_at'])).'</td><td>'.View::e($h['name'] ?? 'Автоматски').'</td><td>'.($h['result_json'] ? 'Со AI сумарија' : 'Само факти').'</td></tr>';
        }
        $body .= '<section class="card"><h2>Претходни брифинзи на секторот</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Недела</th><th>Генерирано на</th><th>Од</th><th>Вид</th></tr></thead><tbody>'.($history ?: '<tr><td colspan="4">—</td></tr>').'</tbody></table></div></section>';
        $this->view()->render('Неделен брифинг на секторот', $body);
    }

    /** POST /directorate-briefing/generate (directorate_id, week) */
    public function directorateGenerate(): never
    {
        $allowed = $this->scope->directorateBriefingIds($this->userId());
        $directorateId = (int) $this->request->input('directorate_id', 0);
        if ($allowed === [] || ($allowed !== null && !in_array($directorateId, $allowed, true))) throw new HttpException(403, 'Немате пристап до брифингот на овој сектор.');
        if (!$this->db->scalar('SELECT COUNT(*) FROM directorates WHERE id=:id', ['id'=>$directorateId])) throw new HttpException(404, 'Секторот не е пронајден.');
        $week = MayorBriefing::weekStart($this->weekInput());
        @set_time_limit(180);
        $id = (new MayorBriefing($this->db, $this->client()))->generate($week, $this->userId(), $directorateId);
        $this->audit->record($this->userId(), 'directorate_briefing.generated', 'MayorBriefing', $id, [], ['week_start'=>$week, 'directorate_id'=>$directorateId]);
        $error = $this->db->scalar('SELECT ai_error FROM mayor_briefings WHERE id=:id', ['id'=>$id]);
        $this->redirect('/directorate-briefing?id='.$id, $error ? 'Брифингот е генериран со фактите; AI сумаријата не успеа.' : 'Брифингот на секторот е генериран.');
    }

    /** GET /mayor-agenda/{id}/prepare */
    public function prepare(int $itemId): never
    {
        $item = $this->agendaItem($itemId);
        $brief = $this->db->one('SELECT TOP 1 b.*,u.name generated_by_name,cu.name confirmed_by_name FROM mayor_meeting_briefs b LEFT JOIN users u ON u.id=b.generated_by LEFT JOIN users cu ON cu.id=b.confirmed_by WHERE b.agenda_item_id=:id ORDER BY b.id DESC', ['id'=>$itemId]);
        $canConfirm = $this->scope->canAccessMayorBriefing($this->userId());
        $body = '<div class="page-actions"><a class="button button-secondary" href="/mayor-agenda">← Агенда на Градоначалникот</a>'
            .($brief && $brief['confirmed_at'] ? '' : '<form method="post" action="/mayor-agenda/'.$itemId.'/prepare">'.$this->view()->csrf().'<button class="primary">'.($brief ? 'Освежи ја подготовката' : 'Подготви средба').'</button></form>').'</div>';
        $body .= '<p class="muted">'.View::e($item['directorate_code'].' · '.$item['directorate_name']).' · приоритет '.View::e(MayorBriefing::PRIORITIES[$item['priority']] ?? $item['priority']).' · '.View::e($item['discussion_date'] ? 'за '.MayorBriefing::date((string) $item['discussion_date']) : 'без датум').' · статус '.View::e($item['status']).'</p>';
        if (!$brief) {
            $body .= '<section class="card"><p>Средбата сѐ уште не е подготвена. „Подготви средба“ ја собира моменталната состојба (задачите, буџетот, извештаите, ризиците, предметите, претходните одлуки) и, кога AI е конфигуриран, ги пишува сумаријата, клучните точки и прашањата за секторот.</p></section>';
            $this->view()->render('Подготовка на средбата', $body);
        }
        $facts = json_decode((string) $brief['facts_json'], true) ?: ['items'=>[], 'title'=>''];
        $result = $brief['result_json'] ? (json_decode((string) $brief['result_json'], true) ?: null) : null;
        $body .= '<section class="card"><h2>'.View::e($facts['title']).'</h2><p class="muted">Подготвено на '.View::e($this->localTime((string) $brief['created_at'])).' од '.View::e($brief['generated_by_name'] ?? '—').($brief['ai_model'] ? ' · AI' : '').'</p>';
        if ($brief['ai_error']) $body .= '<div class="alert alert--error" role="alert">AI сумаријата не успеа; подолу се фактите.</div>';
        if ($result) {
            $body .= '<p>'.View::e($result['summary']).' '.$this->refs($result['summary_refs'] ?? []).'</p>';
            if ($result['key_points']) { $body .= '<h3>Клучни точки</h3><ul>'; foreach ($result['key_points'] as $p) $body .= '<li>'.View::e($p['text']).' '.$this->refs($p['refs']).'</li>'; $body .= '</ul>'; }
            if ($result['questions']) { $body .= '<h3>Прашања за секторот</h3><ol>'; foreach ($result['questions'] as $q) $body .= '<li>'.View::e($q['text']).' '.$this->refs($q['refs']).'</li>'; $body .= '</ol>'; }
        }
        $body .= '</section><section class="card"><h2>Факти</h2>'.$this->factList($facts['items']).'</section>';

        $draft = json_decode((string) ($brief['decisions_json'] ?? ''), true) ?: [];
        if ($brief['confirmed_at']) {
            $body .= '<section class="card"><h2>Потврдени одлуки</h2><p class="muted">Потврдено на '.View::e($this->localTime((string) $brief['confirmed_at'])).' од '.View::e($brief['confirmed_by_name'] ?? '—').'</p><p>'.nl2br(View::e($brief['decisions_text'])).'</p>'
                .($brief['notes'] ? '<details><summary>Белешки од средбата</summary><p>'.nl2br(View::e($brief['notes'])).'</p></details>' : '').'</section>';
        } else {
            $body .= '<section class="card"><h2>Белешки од средбата</h2><p class="muted">Напишете накратко што е одлучено и кој е одговорен. AI ги претвора белешките во нацрт-одлуки со одговорно лице и рок, кои ги уредувате пред потврдата. Не пишувајте имиња на граѓани или странки.</p>'
                .'<form method="post" action="/mayor-agenda/'.$itemId.'/prepare/'.(int) $brief['id'].'/notes" class="form-grid">'.$this->view()->csrf().'<label class="wide">Белешки<textarea name="notes" rows="6" maxlength="8000" required>'.View::e($brief['notes'] ?? '').'</textarea></label><button class="button-secondary">Изготви одлуки од белешките</button></form></section>';
            if ($canConfirm) {
                $text = (string) ($brief['decisions_text'] ?? '');
                $body .= '<section class="card"><h2>Одлуки и следни чекори</h2>'.($draft === [] && $brief['notes'] ? '<p class="alert">AI не пронајде одлуки во белешките или не е конфигуриран. Напишете ги одлуките подолу.</p>' : '')
                    .'<form method="post" action="/mayor-agenda/'.$itemId.'/prepare/'.(int) $brief['id'].'/confirm" class="form-grid">'.$this->view()->csrf()
                    .'<label class="wide">Одлуки (една по ред: одлука — Одговорно лице — Рок)<textarea name="decisions_text" rows="8" maxlength="8000" required>'.View::e($text).'</textarea></label>'
                    .($item['status'] === 'open' ? '<label class="checkbox"><input type="checkbox" name="mark_discussed" value="1" checked> Означи ја точката од агендата како разгледана</label>' : '')
                    .'<button class="primary">Потврди ги одлуките</button></form></section>';
            }
        }
        $this->view()->render('Подготовка на средбата', $body);
    }

    /** POST /mayor-agenda/{id}/prepare */
    public function prepareGenerate(int $itemId): never
    {
        $this->agendaItem($itemId);
        $open = $this->db->scalar('SELECT COUNT(*) FROM mayor_meeting_briefs WHERE agenda_item_id=:id AND confirmed_at IS NOT NULL', ['id'=>$itemId]);
        if ((int) $open > 0) throw new HttpException(409, 'Одлуките од оваа средба се потврдени; подготовката повеќе не се освежува.');
        @set_time_limit(180);
        $id = (new MayorMeetingBrief($this->db, $this->client()))->prepare($itemId, $this->userId());
        $this->audit->record($this->userId(), 'mayor_meeting.prepared', 'MayorMeetingBrief', $id, [], ['agenda_item_id'=>$itemId]);
        $this->redirect('/mayor-agenda/'.$itemId.'/prepare', 'Средбата е подготвена.');
    }

    /** POST /mayor-agenda/{id}/prepare/{brief}/notes */
    public function notes(int $itemId, int $briefId): never
    {
        $this->agendaItem($itemId);
        $brief = $this->openBrief($itemId, $briefId);
        $notes = $this->requireField('notes', 8000);
        @set_time_limit(180);
        $out = (new MayorMeetingBrief($this->db, $this->client()))->draftDecisions((int) $brief['id'], $notes);
        $this->audit->record($this->userId(), 'mayor_meeting.notes', 'MayorMeetingBrief', $briefId, [], ['decisions'=>count($out['decisions'])]);
        $this->redirect('/mayor-agenda/'.$itemId.'/prepare', $out['error'] ? 'Белешките се зачувани; изготвувањето одлуки со AI не успеа.' : 'Белешките се зачувани и изготвени '.count($out['decisions']).' draft-vendime.');
    }

    /** POST /mayor-agenda/{id}/prepare/{brief}/confirm */
    public function confirm(int $itemId, int $briefId): never
    {
        $this->requireBriefing();
        $item = $this->agendaItem($itemId);
        $this->openBrief($itemId, $briefId);
        $text = $this->requireField('decisions_text', 8000);
        $discussed = ($_POST['mark_discussed'] ?? '') === '1' && $item['status'] === 'open';
        $this->db->transaction(function () use ($itemId, $briefId, $text, $discussed): void {
            $changed = $this->db->execute('UPDATE mayor_meeting_briefs SET decisions_text=:text,confirmed_by=:user,confirmed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id AND agenda_item_id=:item AND confirmed_at IS NULL', ['text'=>$text, 'user'=>$this->userId(), 'id'=>$briefId, 'item'=>$itemId]);
            if ($changed !== 1) throw new HttpException(409, 'Одлуките се веќе потврдени.');
            if ($discussed) $this->db->execute("UPDATE mayor_agenda_items SET status='discussed',resolved_by=:user,resolved_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id AND status='open'", ['user'=>$this->userId(), 'id'=>$itemId]);
            $this->audit->record($this->userId(), 'mayor_meeting.decisions_confirmed', 'MayorMeetingBrief', $briefId, [], ['agenda_item_id'=>$itemId, 'decisions'=>$text, 'item_discussed'=>$discussed]);
        });
        $this->redirect('/mayor-agenda/'.$itemId.'/prepare', 'Потврдени се одлуките'.($discussed ? ' и точката е означена како разгледана.' : '.'));
    }

    /** Agenda item the user may see: the cabinet sees all, a director only the items of their directorate. */
    private function agendaItem(int $itemId): array
    {
        if (!$this->scope->canAccessMayorAgenda($this->userId())) throw new HttpException(403, 'Немате дозвола за агендата на Градоначалникот.');
        $item = (new MayorMeetingBrief($this->db))->item($itemId);
        if (!$item || !$this->scope->canManageMayorAgenda($this->userId(), (int) $item['directorate_id'])) throw new HttpException(404, 'Точката од агендата не е пронајдена.');
        return $item;
    }

    private function openBrief(int $itemId, int $briefId): array
    {
        $brief = $this->db->one('SELECT * FROM mayor_meeting_briefs WHERE id=:id AND agenda_item_id=:item', ['id'=>$briefId, 'item'=>$itemId]);
        if (!$brief) throw new HttpException(404, 'Подготовката на средбата не е пронајдена.');
        if ($brief['confirmed_at']) throw new HttpException(409, 'Одлуките од оваа средба се потврдени.');
        return $brief;
    }

    private function briefingHtml(array $briefing): string
    {
        $facts = json_decode((string) $briefing['facts_json'], true) ?: ['sections'=>[]];
        $result = $briefing['result_json'] ? (json_decode((string) $briefing['result_json'], true) ?: null) : null;
        $html = '<p class="muted">Генерирано на '.View::e($this->localTime((string) $briefing['created_at'])).' од '.View::e($briefing['generated_by_name'] ?? 'автоматски распоредувач').' · период '.View::e(MayorBriefing::date($facts['last_week'][0] ?? '').' – '.MayorBriefing::date($facts['next_week'][1] ?? '')).'</p>';
        if ($briefing['ai_error']) $html .= '<div class="alert alert--error" role="alert">AI сумаријата не успеа; брифингот содржи само факти.</div>';
        elseif (!$result) $html .= '<div class="alert">AI не е конфигуриран: брифингот содржи само факти.</div>';
        if ($result) {
            $html .= '<section class="card"><h2>'.View::e($result['headline'] ?: 'Сумарија на неделата').'</h2>';
            if ($result['attention']) { $html .= '<h3>Бараат внимание од Градоначалникот</h3><ol>'; foreach ($result['attention'] as $a) $html .= '<li>'.View::e($a['text']).' '.$this->refs($a['refs']).'</li>'; $html .= '</ol>'; }
            $html .= '</section>';
        }
        foreach (MayorBriefing::SECTIONS as $key => $label) {
            if (!array_key_exists($key, $facts['sections'])) continue;
            $items = $facts['sections'][$key];
            $summary = $result['sections'][$key] ?? null;
            $html .= '<section class="card" id="section-'.$key.'"><h2>'.View::e($label).'</h2>'
                .($summary && $summary['summary'] !== '' ? '<p>'.View::e($summary['summary']).' '.$this->refs($summary['refs']).'</p>' : '')
                .($items ? $this->factList($items) : '<p class="muted">Нема ништо за известување.</p>').'</section>';
        }
        return $html;
    }

    private function factList(array $items): string
    {
        $html = '<ul class="fact-list">';
        foreach ($items as $i) {
            $html .= '<li id="fact-'.View::e($i['ref']).'"><span class="status-badge status-draft">'.View::e($i['ref']).'</span> '.View::e($i['text'])
                .($i['private'] !== '' ? '<br><small class="muted">'.View::e(mb_strimwidth((string) $i['private'], 0, 300, '…')).'</small>' : '')
                .($i['link'] ? ' <a href="'.View::e($i['link']).'">Отвори</a>' : '').'</li>';
        }
        return $html.'</ul>';
    }

    private function refs(array $refs): string
    {
        $links = array_map(static fn(string $r): string => '<a href="#fact-'.View::e($r).'">'.View::e($r).'</a>', $refs);
        return $links ? '<small class="muted">['.implode(', ', $links).']</small>' : '';
    }

    private function weekInput(): string
    {
        $value = (string) ($_POST['week'] ?? $_GET['week'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : date('Y-m-d');
    }

    private function localTime(string $utc): string
    {
        try { return (new \DateTimeImmutable(substr($utc, 0, 19), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i'); }
        catch (\Throwable) { return $utc; }
    }
}
