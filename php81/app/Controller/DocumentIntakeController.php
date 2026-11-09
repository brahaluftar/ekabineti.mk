<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Service\CitizenServiceCatalog;
use EKabineti\Service\DocumentIntake;
use EKabineti\Service\EvidenceUi;
use EKabineti\Service\OpenAIConfig;
use EKabineti\Service\OpenAIResponsesClient;

/** Ngarkimi i raporteve (AI): Word / PDF / Excel → proposals → checked and registered records. */
final class DocumentIntakeController extends Controller
{
    private function view(): View { return new View($this->auth, $this->scope); }
    private function client(): OpenAIResponsesClient { return new OpenAIResponsesClient(OpenAIConfig::fromApplication($this->config)); }
    private function intake(): DocumentIntake { return new DocumentIntake($this->db, $this->scope, $this->userId(), $this->client()); }

    /** Directorates the user can upload for (all active ones for a global scope). */
    private function directorates(): array
    {
        $ids = $this->scope->directorateIds($this->userId());
        $rows = $this->db->all('SELECT id,code,name FROM directorates WHERE active=1 ORDER BY name');
        return $ids === null ? $rows : array_values(array_filter($rows, static fn(array $d): bool => in_array((int) $d['id'], $ids, true)));
    }

    private function requireAccess(): void
    {
        if ($this->directorates() === []) throw new HttpException(403, 'Прикачувањето извештаи е за корисници со пристап до некој сектор.');
    }

    private function load(int $id): array
    {
        $row = $this->db->one('SELECT i.*,d.code directorate_code,d.name directorate_name,s.name sector_name,u.name uploaded_by_name,c.name confirmed_by_name FROM document_intakes i JOIN directorates d ON d.id=i.directorate_id LEFT JOIN citizen_service_sectors s ON s.id=i.sector_id LEFT JOIN users u ON u.id=i.uploaded_by LEFT JOIN users c ON c.id=i.confirmed_by WHERE i.id=:id', ['id'=>$id]);
        if (!$row || !$this->scope->allowsDirectorate($this->userId(), (int) $row['directorate_id'])) throw new HttpException(404, 'Документот не е пронајден.');
        return $row;
    }

    /** GET /document-intake */
    public function index(): never
    {
        $this->requireAccess();
        $ai = $this->client()->isConfigured();
        $body = '<div class="page-actions"><a class="button" href="/document-intake#upload" data-dialog-open="document-intake-upload">Прикачи извештај</a></div>'
            .'<section class="card"><h2>Прикачување извештаи со AI</h2><p class="muted">Секторите и одделенијата ги прикачуваат своите извештаи во Word, PDF или Excel. Системот го чита текстот, ги крие матичните броеви, е-поштите, телефоните и IBAN-ите, а AI предлага што треба да се евидентира: <strong>извештаи за напредок</strong> и <strong>task-e</strong> кон постојните проекти, <strong>projekte</strong> нови, <strong>предмети</strong> и <strong>rreziqe</strong>. Ништо не се евидентира без ваша проверка: секој предлог можете да го поправите или отстраните, а потоа го евидентирате со истите правила, дозволи и ревизија како во вообичаените обрасци.</p>'
            .($ai ? '' : '<div class="alert alert--error" role="alert">AI не е конфигуриран: документите не може да се анализираат додека администраторот не го активира.</div>')
            .'</section>';
        $body .= '<section class="card" id="upload"><h2>Прикачи извештај</h2>'.$this->uploadForm(false).'</section>';
        [$where, $params] = $this->scope->sql($this->userId(), 'i.directorate_id', 'intake');
        $rows = '';
        $labels = ['ready'=>['Подготвено за проверка', 'status-on_hold'], 'confirmed'=>['Евидентирано', 'status-active'], 'failed'=>['Неуспешно', 'status-overdue'], 'cancelled'=>['Откажано', 'status-cancelled']];
        foreach ($this->db->all("SELECT TOP 50 i.id,i.file_name,i.file_type,i.status,i.created_at,i.result_json,d.code directorate_code,u.name uploaded_by_name FROM document_intakes i JOIN directorates d ON d.id=i.directorate_id LEFT JOIN users u ON u.id=i.uploaded_by WHERE {$where} ORDER BY i.id DESC", $params) as $r) {
            [$label, $class] = $labels[$r['status']] ?? [$r['status'], 'status-draft'];
            $created = $r['result_json'] ? count(json_decode((string) $r['result_json'], true) ?: []) : 0;
            $rows .= '<tr><td><a href="/document-intake/'.(int) $r['id'].'">'.View::e($r['file_name']).'</a><br><small class="muted">'.View::e(strtoupper((string) $r['file_type'])).'</small></td><td>'.View::e($r['directorate_code']).'</td><td>'.View::e($r['uploaded_by_name'] ?? '').'<br><small class="muted">'.View::e($this->localTime((string) $r['created_at'])).'</small></td><td><span class="status-badge '.$class.'">'.View::e($label).'</span>'.($created ? ' <small>'.$created.' regjistrime</small>' : '').'</td></tr>';
        }
        $body .= '<section class="card"><h2>Прикачени документи</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Документ</th><th>Сектор</th><th>Прикачено од</th><th>Статус</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="4">Сѐ уште нема документи.</td></tr>').'</tbody></table></div></section>';
        $body .= '<dialog class="modal" id="document-intake-upload" aria-labelledby="document-intake-upload-title" data-dialog><header class="modal__header"><div><p class="eyebrow">AI</p><h2 id="document-intake-upload-title">Прикачи извештај</h2></div><button type="button" class="icon-button" data-modal-close aria-label="Затвори"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>'.$this->uploadForm(true).'</dialog>';
        $this->view()->render('Прикачување извештаи (AI)', $body);
    }

    /** POST /document-intake */
    public function upload(): never
    {
        $this->requireAccess();
        $directorateId = (int) $this->request->input('directorate_id', 0);
        if (!in_array($directorateId, array_map('intval', array_column($this->directorates(), 'id')), true)) throw new HttpException(403, 'Изберете сектор до кој имате пристап.');
        $sectorId = (int) $this->request->input('sector_id', 0) ?: null;
        if ($sectorId !== null && !$this->db->scalar('SELECT COUNT(*) FROM citizen_service_sectors WHERE id=:id AND directorate_id=:d', ['id'=>$sectorId, 'd'=>$directorateId])) throw new HttpException(422, 'Одделението не припаѓа на секторот.');
        if ((string) $this->request->input('privacy_confirmed', '') !== '1') throw new HttpException(422, 'Потврдете дека документот не содржи имиња на граѓани или странки, или дека тие се анонимизирани.');
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpException(422, 'Изберете го извештајот (Word, PDF или Excel).');
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, DocumentIntake::EXTENSIONS, true)) throw new HttpException(422, 'Дозволени се Word (.docx, .doc), PDF и Excel (.xlsx).');
        if ((int) $file['size'] > DocumentIntake::MAX_BYTES) throw new HttpException(422, 'Документот надминува 20 MB.');
        $key = 'document-intake/'.date('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$extension;
        $path = APP_ROOT.'/storage/private/'.$key;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) throw new \RuntimeException('Приватниот директориум не е создаден.');
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) throw new \RuntimeException('Прикачувањето не успеа.');
        $name = mb_substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 255);
        @set_time_limit(240);
        $service = $this->intake();
        $status = 'ready'; $error = null; $summary = null; $items = []; $chars = 0; $truncated = false; $counts = [];
        try {
            $text = DocumentIntake::extractText($path, $extension);
            [$text, $counts] = DocumentIntake::redact($text);
            $chars = mb_strlen($text);
            if (trim($text) === '') throw new \RuntimeException('Документот нема текст за анализа.');
            if ($chars > DocumentIntake::MAX_CHARS) { $text = mb_substr($text, 0, DocumentIntake::MAX_CHARS); $truncated = true; }
            [$summary, $items] = $service->propose($text, $directorateId);
        } catch (\Throwable $exception) {
            $status = 'failed'; $error = mb_substr($exception->getMessage(), 0, 1000);
        }
        $id = $this->db->insertId('INSERT INTO document_intakes(directorate_id,sector_id,file_name,file_type,file_size,file_hash,storage_key,text_chars,text_truncated,redactions,status,summary,proposal_json,ai_model,error,uploaded_by,created_at) VALUES(:d,:s,:name,:type,:size,:hash,:key,:chars,:truncated,:redactions,:status,:summary,:proposal,:model,:error,:user,SYSUTCDATETIME())', [
            'd'=>$directorateId, 's'=>$sectorId, 'name'=>$name, 'type'=>$extension, 'size'=>(int) $file['size'], 'hash'=>hash_file('sha256', $path), 'key'=>$key, 'chars'=>$chars, 'truncated'=>$truncated ? 1 : 0,
            'redactions'=>$counts ? json_encode($counts) : null, 'status'=>$status, 'summary'=>$summary, 'proposal'=>$items ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'model'=>$status === 'ready' ? $this->client()->model() : null, 'error'=>$error, 'user'=>$this->userId(),
        ]);
        $this->audit->record($this->userId(), 'document_intake.uploaded', 'DocumentIntake', $id, [], ['file_name'=>$name, 'directorate_id'=>$directorateId, 'sector_id'=>$sectorId, 'status'=>$status, 'proposals'=>count($items), 'redactions'=>$counts]);
        $this->redirect('/document-intake/'.$id, $status === 'ready' ? 'Документот е анализиран: '.count($items).' предлози. Проверете ги пред евидентирањето.' : 'Документот не е анализиран: '.$error);
    }

    /** GET /document-intake/{id} */
    public function show(int $id): never
    {
        $this->requireAccess();
        $intake = $this->load($id);
        $body = '<div class="page-actions"><a class="button button-secondary" href="/document-intake">← Прикачување извештаи</a></div>';
        $redactions = json_decode((string) ($intake['redactions'] ?? ''), true) ?: [];
        $names = ['personal_number'=>'матични броеви', 'email'=>'email', 'phone'=>'telefona', 'iban'=>'IBAN'];
        $masked = implode(', ', array_map(static fn(string $k, int $n): string => $n.' '.($names[$k] ?? $k), array_keys($redactions), $redactions));
        $body .= '<section class="card"><h2>'.View::e($intake['file_name']).'</h2><p class="muted">'.View::e($intake['directorate_code'].' · '.$intake['directorate_name']).($intake['sector_name'] ? ' · '.View::e($intake['sector_name']) : '').' · прикачено од '.View::e($intake['uploaded_by_name'] ?? '').' во '.View::e($this->localTime((string) $intake['created_at'])).' · '.number_format((int) $intake['text_chars'], 0, ',', '.').' знаци текст'.($masked ? ' · скриени: '.View::e($masked) : '').'</p>'
            .((int) $intake['text_truncated'] ? '<div class="alert">Документот е премногу долг: анализирани се само '.number_format(DocumentIntake::MAX_CHARS, 0, ',', '.').' првите знаци. Поделете го во повеќе документи за остатокот.</div>' : '')
            .($intake['summary'] ? '<p>'.View::e($intake['summary']).'</p>' : '').'</section>';
        $duplicate = $this->db->one("SELECT TOP 1 id,confirmed_at FROM document_intakes WHERE file_hash=:hash AND directorate_id=:d AND status='confirmed' AND id<>:id ORDER BY id DESC", ['hash'=>$intake['file_hash'], 'd'=>$intake['directorate_id'], 'id'=>$id]);
        if ($duplicate && $intake['status'] === 'ready') $body .= '<div class="alert alert--error" role="alert">Овој документ е веќе евидентиран (<a href="/document-intake/'.(int) $duplicate['id'].'">#'.(int) $duplicate['id'].'</a>). Проверете да не ги дуплирате податоците.</div>';

        if ($intake['status'] === 'failed') {
            $body .= '<section class="card"><div class="alert alert--error" role="alert">'.View::e($intake['error'] ?? 'Анализата не успеа.').'</div><p><a class="button" href="/document-intake#upload">Прикачи повторно</a></p></section>';
            $this->view()->render('Прикачен документ', $body);
        }
        if (in_array($intake['status'], ['confirmed', 'cancelled'], true)) {
            $list = '';
            foreach (json_decode((string) ($intake['result_json'] ?? ''), true) ?: [] as $r) $list .= '<li><span class="status-badge status-draft">'.View::e(DocumentIntake::TYPES[$r['type']] ?? $r['type']).'</span> <a href="'.View::e($r['link']).'">'.View::e($r['label']).'</a></li>';
            $body .= '<section class="card"><h2>'.($intake['status'] === 'confirmed' ? 'Евидентирано на '.View::e($this->localTime((string) $intake['confirmed_at'])).' од '.View::e($intake['confirmed_by_name'] ?? '') : 'Откажано').'</h2>'.($list ? '<ul class="fact-list">'.$list.'</ul>' : '').'</section>';
            $this->view()->render('Прикачен документ', $body);
        }

        $service = $this->intake();
        $items = json_decode((string) ($intake['proposal_json'] ?? ''), true) ?: [];
        $directorateId = (int) $intake['directorate_id'];
        $lists = ['project'=>$service->projects($directorateId), 'manager'=>$service->managers($directorateId)];
        $form = '<form method="post" action="/document-intake/'.$id.'/confirm" class="stack">'.$this->view()->csrf();
        if ($items === []) $form .= '<section class="card"><p>AI не пронајде извештаи за напредок, задачи, проекти, предмети или ризици во овој документ.</p></section>';
        foreach ($items as $n => $item) {
            $type = (string) $item['type'];
            [, $errors] = $service->validate($type, array_map(static fn($v): string => $v === null ? '' : (string) $v, (array) $item['fields']), $directorateId);
            $form .= '<fieldset class="citizen-service-row"><legend><label class="checkbox"><input type="checkbox" name="items['.$n.'][include]" value="1"'.($errors ? '' : ' checked').'> '.($n + 1).'. '.View::e(DocumentIntake::TYPES[$type] ?? $type).'</label> <small class="muted">веродостојност: '.View::e(['high'=>'висока', 'medium'=>'mesatare', 'low'=>'ниска'][$item['confidence'] ?? 'medium'] ?? '').'</small></legend>'
                .'<input type="hidden" name="items['.$n.'][type]" value="'.View::e($type).'">'
                .($item['excerpt'] ? '<p class="muted">“'.View::e($item['excerpt']).'”</p>' : '')
                .($errors ? '<p class="text-danger">За пополнување: '.View::e(implode(' ', $errors)).'</p>' : '')
                .'<div class="form-grid">';
            foreach (DocumentIntake::FIELDS[$type] as $field => [$label, $kind, $required]) $form .= $this->field('items['.$n.'][f]['.$field.']', $label.($required ? ' *' : ''), $kind, $item['fields'][$field] ?? null, $lists);
            $form .= '</div></fieldset>';
        }
        $form .= '<div class="form-actions"><button class="primary">Евидентирај ги избраните предлози</button></div></form>';
        $body .= '<section class="card"><h2>Предлози</h2><p class="muted">Означете ги предлозите што сакате да ги евидентирате и поправете ги полињата. Предлозите со полиња што недостасуваат се неозначени. Задачите се евидентираат со тежина 0% (прилагодете ја во проектот); новите проекти се евидентираат како нацрт.</p></section>'.$form;
        $body .= '<section class="card"><form method="post" action="/document-intake/'.$id.'/cancel">'.$this->view()->csrf().'<button class="button-secondary">Откажи го документот без да се евидентира ништо</button></form></section>';
        $this->view()->render('Проверка на предлозите', $body);
    }

    /** POST /document-intake/{id}/confirm */
    public function confirm(int $id): never
    {
        $this->requireAccess();
        $this->load($id);
        $items = [];
        foreach (is_array($_POST['items'] ?? null) ? $_POST['items'] : [] as $item) {
            if (!is_array($item) || ($item['include'] ?? '') !== '1') continue;
            $items[] = ['type'=>(string) ($item['type'] ?? ''), 'fields'=>is_array($item['f'] ?? null) ? array_map(static fn($v): string => is_string($v) ? $v : '', $item['f']) : []];
        }
        @set_time_limit(120);
        try { $created = $this->intake()->register($id, $items, $this->audit, $this->notifications); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $this->redirect('/document-intake/'.$id, 'Евидентирани се '.count($created).' податоци од документот.');
    }

    /** POST /document-intake/{id}/cancel */
    public function cancel(int $id): never
    {
        $this->requireAccess();
        $intake = $this->load($id);
        if ($intake['status'] !== 'ready') throw new HttpException(409, 'Документот повеќе не е во проверка.');
        $this->db->execute("UPDATE document_intakes SET status='cancelled',confirmed_by=:user,confirmed_at=SYSUTCDATETIME() WHERE id=:id AND status='ready'", ['user'=>$this->userId(), 'id'=>$id]);
        $this->audit->record($this->userId(), 'document_intake.cancelled', 'DocumentIntake', $id, [], []);
        $this->redirect('/document-intake', 'Документот е откажан; ништо не е евидентирано.');
    }

    private function uploadForm(bool $dialog): string
    {
        $directorates = '';
        foreach ($this->directorates() as $d) $directorates .= '<option value="'.(int) $d['id'].'">'.View::e($d['code'].' · '.$d['name']).'</option>';
        $sectors = '<option value="">— Без одделение —</option>';
        foreach ((new CitizenServiceCatalog($this->db))->sectors($this->scope->directorateIds($this->userId())) as $s) $sectors .= '<option value="'.(int) $s['id'].'">'.View::e($s['directorate_code'].' · '.$s['name']).'</option>';
        $fields = '<label>Сектор<select name="directorate_id" required>'.$directorates.'</select></label><label>Одделение (опционално)<select name="sector_id">'.$sectors.'</select></label>'
            .EvidenceUi::dropzone('file', false, true, 'Word (.docx, .doc), PDF или Excel (.xlsx), до 20 MB', 'Извештај', '.docx,.doc,.pdf,.xlsx,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            .'<label class="checkbox wide"><input type="checkbox" name="privacy_confirmed" value="1" required> Документот не содржи имиња на граѓани или странки, или тие се анонимизирани. Матичните броеви, е-поштите, телефоните и IBAN-ите автоматски се бришат пред анализата со AI.</label>'
            .'<p class="wide muted">Анализата обично трае 20–60 секунди. Потоа проверете ги предлозите и евидентирајте ги само оние што се точни.</p>';
        if (!$dialog) return '<form method="post" action="/document-intake" enctype="multipart/form-data" class="form-grid">'.$this->view()->csrf().$fields.'<div><button class="primary">Прикачи и анализирај</button></div></form>';
        return '<form method="post" action="/document-intake" enctype="multipart/form-data" class="modal__form">'.$this->view()->csrf().'<div class="modal__body form-grid">'.$fields.'</div><footer class="modal__footer"><span class="modal__keys muted">Esc затвора</span><div class="inline-actions"><button type="button" class="button button-secondary" data-modal-close>Откажи</button><button class="primary">Прикачи и анализирај</button></div></footer></form>';
    }

    private function field(string $name, string $label, string $kind, mixed $value, array $lists): string
    {
        $v = $value === null ? '' : (string) $value;
        $select = static function (array $options, bool $empty) use ($name, $label, $v): string {
            $html = '<label>'.View::e($label).'<select name="'.View::e($name).'">'.($empty ? '<option value="">—</option>' : '');
            foreach ($options as $key => $text) $html .= '<option value="'.View::e((string) $key).'"'.((string) $key === $v ? ' selected' : '').'>'.View::e($text).'</option>';
            return $html.'</select></label>';
        };
        return match ($kind) {
            'project'=>$select(array_column(array_map(static fn(array $p): array => ['id'=>$p['id'], 'label'=>$p['code'].' · '.$p['name']], $lists['project']), 'label', 'id'), true),
            'manager'=>$select(array_column($lists['manager'], 'name', 'id'), true),
            'task_status'=>$select(DocumentIntake::TASK_STATUSES, false),
            'priority'=>$select(DocumentIntake::PRIORITIES, false),
            'risk_category'=>$select(DocumentIntake::RISK_CATEGORIES, true),
            'risk_response'=>$select(DocumentIntake::RISK_RESPONSES, false),
            'budget_category'=>$select(DocumentIntake::BUDGET_CATEGORIES, false),
            'date'=>'<label>'.View::e($label).'<input type="date" name="'.View::e($name).'" value="'.View::e($v).'"></label>',
            'percent'=>'<label>'.View::e($label).'<input type="number" min="0" max="100" name="'.View::e($name).'" value="'.View::e($v).'"></label>',
            'score'=>'<label>'.View::e($label).'<input type="number" min="1" max="5" name="'.View::e($name).'" value="'.View::e($v).'"></label>',
            'text5000'=>'<label class="wide">'.View::e($label).'<textarea name="'.View::e($name).'" maxlength="5000" rows="3">'.View::e($v).'</textarea></label>',
            default=>'<label>'.View::e($label).'<input name="'.View::e($name).'" maxlength="'.(int) preg_replace('/\D/', '', $kind).'" value="'.View::e($v).'"></label>',
        };
    }

    private function localTime(string $utc): string
    {
        try { return (new \DateTimeImmutable(substr($utc, 0, 19), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i'); }
        catch (\Throwable) { return $utc; }
    }
}
