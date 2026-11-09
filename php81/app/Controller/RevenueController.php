<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\Pagination;
use EKabineti\Core\Request;
use EKabineti\Core\View;
use EKabineti\Service\EvidenceUi;
use EKabineti\Service\RevenueImport;
use EKabineti\Service\RevenueReport;
use EKabineti\Service\TabularExport;

/**
 * Të hyrat: the daily revenue report of the treasury account, imported through a dialog, with every payment
 * categorised by type of revenue (UNIREF rules editable in /revenues/categories).
 */
final class RevenueController extends Controller
{
    private const STATUS = ['new'=>'Нови', 'existing'=>'Постојат во системот', 'duplicate_in_file'=>'Повторени во датотеката', 'invalid'=>'Со грешки'];
    private const BATCH_STATUS = ['cleared'=>['Уплатите се избришани','status-cancelled'], 'queued'=>['Во ред за читање','status-draft'], 'processing'=>['Се чита','status-draft'], 'posting'=>['Се евидентира','status-draft'], 'failed'=>['Читањето не успеа','status-cancelled'], 'ready'=>['Подготвено за потврда','status-active'], 'unchanged'=>['Ништо ново','status-draft'], 'invalid'=>['Неважечки','status-cancelled'], 'posted'=>['Увезен','status-closed'], 'cancelled'=>['Откажан','status-cancelled'], 'preview'=>['Во обработка','status-draft']];
    private const METHOD = ['uniref'=>'Според UNIREF', 'keyword'=>'Според UNIREF и описот', 'group'=>'Според групата', 'manual'=>'Рачно', 'none'=>'Некласифицирано'];
    private const PAYER_TYPE = ['individual'=>'Физичко лице', 'business'=>'Biznes / institucion', 'unknown'=>'—'];
    private const ID_TYPE = ['personal_number'=>'ЕМБГ', 'business_number'=>'Даночен број (ЕДБ)'];

    private function view(): View { return new View($this->auth, $this->scope); }
    private function service(): RevenueImport { return new RevenueImport($this->db, $this->audit, $this->userId()); }
    private function canImport(): bool { return $this->scope->canImportRevenues($this->userId()); }
    private function requireImport(): void { if (!$this->canImport()) throw new HttpException(403, 'Само Секторот за финансии може да го увезе извештајот за приходите.'); }

    /** GET /revenues: totals by group and type, payments by day, the payment list and the import dialog. */
    public function index(): never
    {
        [$where, $params, $filters] = $this->filters();
        $from = 'FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id WHERE '.$where;
        $totals = $this->db->one("SELECT COUNT(*) payments,COALESCE(SUM(t.amount),0) amount,SUM(CASE WHEN c.code='unclassified' THEN 1 ELSE 0 END) unclassified,SUM(CASE WHEN t.warnings IS NOT NULL THEN 1 ELSE 0 END) flagged,MIN(t.payment_date) date_from,MAX(t.payment_date) date_to ".$from, $params) ?? [];
        $amount = (float) ($totals['amount'] ?? 0);
        $groups = [];
        // Group cards are the first level of the drill-down: they keep the other filters but not the group and type.
        $savedGet = $_GET;
        $_GET = array_diff_key($_GET, ['group'=>1, 'category'=>1]);
        [$groupWhere, $groupParams] = $this->filters();
        $_GET = $savedGet;
        foreach ($this->db->all('SELECT t.revenue_group,COUNT(*) payments,SUM(t.amount) amount FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id WHERE '.$groupWhere.' GROUP BY t.revenue_group', $groupParams) as $row) $groups[(string) $row['revenue_group']] = $row;

        $body = '<div class="page-actions">'
            .($this->canImport() ? '<a class="button" href="/revenues/import" data-dialog-open="revenue-import">'.$this->icon('upload').'Увези дневен извештај</a>' : '')
            .'<a class="button button-secondary" href="/revenues/categories">Видови приходи</a>'
            .($this->canImport() ? '<a class="button button-secondary" href="/revenues/maintenance">Одржување на податоците</a>' : '')
            .'<a class="button button-secondary" href="/revenues.xlsx?'.View::e(http_build_query($filters)).'">Извези во Excel</a>'
            .'<a class="button button-secondary" href="/revenues.csv?'.View::e(http_build_query($filters)).'">CSV</a></div>';
        $body .= $this->filterForm($filters);
        $body .= $this->drillPath($filters);
        $body .= '<section class="kpi-grid" aria-label="Показатели за приходите">'
            .$this->kpi('Вкупно приходи', View::money($amount), $totals['date_from'] ? 'Од '.$this->displayDate((string) $totals['date_from']).' до '.$this->displayDate((string) $totals['date_to']) : 'Нема уплати за овој филтер')
            .$this->kpi('Уплата', number_format((int) ($totals['payments'] ?? 0), 0, '.', ','), (int) ($totals['payments'] ?? 0) > 0 ? 'Просек '.View::money($amount / max(1, (int) $totals['payments'])) : '')
            .$this->kpi('Некласифицирани', (string) (int) ($totals['unclassified'] ?? 0), '<a href="?'.View::e(http_build_query(['category'=>'unclassified'] + $filters)).'">Погледни и класифицирај</a>')
            .$this->kpi('За проверка', (string) (int) ($totals['flagged'] ?? 0), '<a href="?'.View::e(http_build_query(['review'=>'1'] + $filters)).'">Нецелосен UNIREF или непознат вид</a>')
            .'</section>';
        $groupCards = '';
        foreach (RevenueReport::GROUPS as $code => $label) {
            $row = $groups[$code] ?? ['payments'=>0, 'amount'=>0];
            $active = ($filters['group'] ?? '') === (string) $code;
            $query = $active ? array_diff_key($filters, ['group'=>1, 'category'=>1]) : ['group'=>$code] + array_diff_key($filters, ['category'=>1]);
            $groupCards .= '<a class="stat-filter'.($active ? ' is-active' : '').'" href="?'.View::e(http_build_query($query)).'"'.($active ? ' aria-current="true"' : '').'><span>'.$code.' · '.View::e($label).'</span><strong>'.View::e(View::money($row['amount'])).'</strong><small class="muted">'.(int) $row['payments'].' pagesa</small></a>';
        }
        $body .= '<nav class="stat-filters" aria-label="Филтрирај според групата приходи">'.$groupCards.'</nav>';

        $categories = $this->db->all('SELECT c.code,c.name,c.revenue_group,COUNT(*) payments,SUM(t.amount) amount '.$from.' GROUP BY c.code,c.name,c.revenue_group ORDER BY SUM(t.amount) DESC', $params);
        $rows = '';
        foreach ($categories as $row) {
            $share = $amount > 0 ? round((float) $row['amount'] / $amount * 100, 1) : 0;
            $drill = ['category'=>$row['code']] + ($row['revenue_group'] !== null ? ['group'=>(string) $row['revenue_group']] : []) + $filters;
            $rows .= '<tr><th scope="row"><a href="?'.View::e(http_build_query($drill)).'">'.View::e($row['name']).'</a></th><td>'.View::e($row['revenue_group'] ?? '—').'</td><td class="num right">'.(int) $row['payments'].'</td><td class="num right">'.View::e(View::money($row['amount'])).'</td><td class="progress-cell"><progress class="progress-bar" max="100" value="'.$share.'" aria-hidden="true"></progress><span>'.$share.'%</span></td><td><a href="?'.View::e(http_build_query($drill)).'#payments">Уплати →</a></td></tr>';
        }
        $heading = isset($filters['category']) ? 'Избран вид' : (isset($filters['group']) ? 'Видови во групата '.$filters['group'].' · '.(RevenueReport::GROUPS[$filters['group']] ?? '') : 'Според видот на приходи');
        $body .= '<section class="card"><h2>'.View::e($heading).'</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Вид</th><th>Група</th><th class="num right">Уплата</th><th class="num right">Износ</th><th>Дел</th><th></th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="6">Нема приходи за овој филтер.</td></tr>').'</tbody></table></div></section>';
        if (isset($filters['category']) || isset($filters['group'])) $body .= $this->breakdowns($from, $params, $filters, $amount);

        $daysPager = Pagination::fromRequest('days');
        $days = $daysPager->query($this->db, 'SELECT t.payment_date,COUNT(*) payments,SUM(t.amount) amount,SUM(CASE WHEN t.revenue_group=\'10\' THEN t.amount ELSE 0 END) g10,SUM(CASE WHEN t.revenue_group=\'20\' THEN t.amount ELSE 0 END) g20,SUM(CASE WHEN t.revenue_group=\'30\' THEN t.amount ELSE 0 END) g30,SUM(CASE WHEN t.revenue_group=\'40\' THEN t.amount ELSE 0 END) g40 '.$from.' GROUP BY t.payment_date ORDER BY t.payment_date DESC', $params);
        $rows = '';
        foreach ($days as $row) $rows .= '<tr><th scope="row"><a href="?'.View::e(http_build_query(['from'=>$row['payment_date'], 'to'=>$row['payment_date']] + $filters)).'">'.View::e($this->displayDate((string) $row['payment_date'])).'</a></th><td class="num right">'.(int) $row['payments'].'</td><td class="num right"><strong>'.View::e(View::money($row['amount'])).'</strong></td><td class="num right">'.View::e(View::money($row['g10'])).'</td><td class="num right">'.View::e(View::money($row['g20'])).'</td><td class="num right">'.View::e(View::money($row['g30'])).'</td><td class="num right">'.View::e(View::money($row['g40'])).'</td></tr>';
        $body .= '<section class="card"><h2>Според денот</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Датум</th><th class="num right">Уплата</th><th class="num right">Вкупно</th><th class="num right">10 Данок на имот</th><th class="num right">20 Здравство</th><th class="num right">30 Образование</th><th class="num right">40 Други</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="7">Нема уплати.</td></tr>').'</tbody></table></div>'.$daysPager->nav().'</section>';

        $pager = Pagination::fromRequest();
        $payments = $pager->query($this->db, 'SELECT t.id,t.payment_date,t.amount,t.uniref,t.raw_uniref,t.payer_name,t.raw_payer,t.payer_type,t.identification_number,t.invoice_number,t.taxpayer_number,t.property_number,t.plate_number,t.case_reference,t.channel,t.warnings,c.name category '.$from.' ORDER BY '.$this->orderBy($filters), $params);
        $body .= '<section class="card" id="payments"><h2>'.View::e($this->paymentsHeading($filters)).' <span class="muted">· '.number_format((int) ($totals['payments'] ?? 0), 0, '.', ',').' уплати, '.View::e(View::money($amount)).'</span></h2><div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Датум</th><th class="num right">Износ</th><th>Вид</th><th>UNIREF</th><th>Уплатувач</th><th>Идентификациски бр.</th><th>Бр. на фактура</th><th>Бр. на даночен обврзник / имот</th><th>Регистарски таблички</th><th>Бр. на предмет</th><th>Канал</th></tr></thead><tbody>'.$this->paymentRows($payments).'</tbody></table></div>'.$pager->nav().'</section>';

        $body .= $this->recentImports();
        if ($this->canImport()) $body .= $this->importDialog($this->request->input('import') === '1');
        $this->view()->render('Приходи', $body);
    }

    /** GET /revenues/import: the import form as a page (the dialog without JavaScript). */
    public function importForm(): never
    {
        $this->requireImport();
        $this->view()->render('Увези дневен извештај за приходите', '<p><a class="button button-secondary" href="/revenues">Назад кон приходите</a></p><section class="card">'.$this->importFields(false).'</section>');
    }

    /**
     * POST /revenues/import: stores the file privately, answers at once and reads the report afterwards. The browser is
     * sent to the import page, which follows the progress; the session is released first, so the user can keep working.
     */
    public function importPreview(): never
    {
        $this->requireImport();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpException(422, ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 'Изберете дневен извештај.' : 'Прикачувањето не успеа или датотеката го надминува лимитот на серверот.');
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx','xls','csv'], true)) throw new HttpException(422, 'Дозволени се само XLSX, XLS и CSV.');
        if ((int) $file['size'] > 64 * 1048576) throw new HttpException(422, 'Извештајот надминува 64 MB. Поделете го по период.');
        $duplicates = (string) $this->request->input('duplicates', 'once') === 'separate' ? 'separate' : 'once';
        $id = Request::uuid();
        $directory = APP_ROOT.'/storage/private/imports/'.$id;
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Приватниот директориум не е создаден.');
        $path = $directory.'/source.'.$extension;
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) throw new \RuntimeException('Прикачувањето не успеа.');
        $service = $this->service();
        $service->queue($id, 'imports/'.$id.'/source.'.$extension, ['duplicates'=>$duplicates, 'source_file_name'=>mb_substr(basename((string) $file['name']), 0, 255), 'source_sha256'=>hash_file('sha256', $path) ?: '']);
        $this->respondAndContinue('/revenues/imports/'.$id, 'Извештајот е прикачен и се чита во позадина. Можете да продолжите со работа.');
        $service->process($id);
        exit;
    }

    /** GET /revenues/imports/{id}/status: polled by the import page while the report is read. */
    public function importStatus(string $id): never
    {
        $batch = $this->db->scalar('SELECT type FROM import_batches WHERE id=:id', ['id'=>$id]) === RevenueImport::CLEAR_TYPE ? $this->batch($id, RevenueImport::CLEAR_TYPE) : $this->batch($id);
        session_write_close();
        $summary = json_decode((string) ($batch['summary'] ?? '{}'), true) ?: [];
        $this->json(['status'=>(string) $batch['status'], 'progress'=>(int) ($summary['progress'] ?? 0), 'done'=>!in_array((string) $batch['status'], ['queued','processing','posting'], true)]);
    }

    /** GET /revenues/imports/{id}: preview or result of one report. */
    public function importShow(string $id): never
    {
        $batch = $this->batch($id);
        $summary = json_decode((string) ($batch['summary'] ?? '{}'), true) ?: [];
        $counts = (array) ($summary['counts'] ?? []) + ['new'=>0, 'existing'=>0, 'duplicate_in_file'=>0, 'invalid'=>0];
        $amounts = (array) ($summary['amounts'] ?? []);
        $status = (string) $batch['status'];
        $posted = $status === 'posted';
        [$label, $class] = self::BATCH_STATUS[$status] ?? [$status, 'status-draft'];
        $options = (array) ($summary['options'] ?? []);
        $body = '<p><a class="button button-secondary" href="/revenues">Назад кон приходите</a></p>';
        $body .= '<section class="card"><div class="card-header"><h2>'.View::e(($options['source_file_name'] ?? '') ?: 'Дневен извештај').'</h2><span class="status-badge '.$class.'">'.View::e($label).'</span></div>'
            .'<p class="muted">Прикачено од '.View::e($batch['user_name'] ?? '—').' во '.View::e(substr((string) $batch['created_at'], 0, 16)).($summary['date_from'] ?? null ? ' · нови уплати: '.View::e($this->displayDate((string) $summary['date_from'])).' – '.View::e($this->displayDate((string) $summary['date_to'])) : '')
            .' · идентични редови: '.(($options['duplicates'] ?? 'once') === 'separate' ? 'како посебни уплати' : 'се увезуваат еднаш').'</p>';
        if (in_array($status, ['queued','processing','posting'], true)) {
            $what = match ($status) { 'queued'=>'Извештајот е во редица.', 'processing'=>'Извештајот се чита'.(isset($summary['progress']) ? ' (досега '.number_format((int) $summary['progress'], 0, '.', ',').' редови).' : '.'), default=>'Уплатите се евидентираат.' };
            $body .= '<div class="alert alert--info" role="status" data-import-status="/revenues/imports/'.View::e($id).'/status"><span data-import-progress>'.View::e($what).'</span> Страницата се освежува сама кога ќе заврши; во меѓувреме можете да работите на други страници.</div></section>';
            $this->view()->render('Увоз на приходите', $body);
        }
        if ($status === 'failed') {
            $error = (json_decode((string) ($batch['errors'] ?? '[]'), true) ?: [])[0]['message'] ?? 'Читањето на извештајот не успеа.';
            $body .= '<div class="alert alert--error" role="alert">'.View::e($error).' Ниедна уплата не е евидентирана; обидете се повторно да го прикачите.</div></section>';
            $this->view()->render('Увоз на приходите', $body);
        }
        $same = $status === 'ready' ? $this->service()->sameFileImported((string) ($options['source_sha256'] ?? ''), $id) : null;
        if ($same) $body .= '<div class="alert alert--error" role="alert">Истата датотека е увезена на '.View::e(substr((string) $same['confirmed_at'], 0, 16)).' (<a href="/revenues/imports/'.View::e($same['id']).'">види го увозот</a>). Уплатите што постојат нема да се евидентираат двапати.</div>';
        if ($status === 'invalid') $body .= '<div class="alert alert--error" role="alert">Датотеката ја нема структурата на дневниот извештај (Бр · Износ · Корисник · Уплатувач · Група · Опис · Датум · UNIREF), или повеќе од половина од редовите немаат читлив датум и износ.</div>';
        $body .= '<div class="kpi-grid kpi-grid--five">'
            .$this->kpi('Редови во датотеката', (string) (int) ($summary['rows'] ?? 0), '')
            .$this->kpi($posted ? 'Увезени се' : 'Нови – ќе се увезат', (string) ($posted ? (int) ($summary['posted']['imported'] ?? 0) : (int) $counts['new']), View::money($posted ? ($summary['posted']['amount'] ?? 0) : ($amounts['new'] ?? 0)))
            .$this->kpi('Постојат во системот', (string) (int) $counts['existing'], 'Не се увезуваат повторно')
            .$this->kpi('Повторени во датотеката', (string) (int) $counts['duplicate_in_file'], 'Не се увезуваат')
            .$this->kpi('Со грешки', (string) (int) $counts['invalid'], 'Не се увезуваат')
            .'</div>';
        if ($posted && (int) ($summary['posted']['skipped_meanwhile'] ?? 0) > 0) $body .= '<p class="muted">'.(int) $summary['posted']['skipped_meanwhile'].' уплати беа увезени во меѓувреме од друго прикачување и не се повторени.</p>';
        if ($status === 'ready' && $this->canImport()) {
            $body .= '<div class="form-actions"><form method="post" action="/revenues/imports/'.View::e($id).'/confirm">'.$this->view()->csrf().'<button class="primary">Увези '.(int) $counts['new'].' нови уплати</button></form>'
                .'<form method="post" action="/revenues/imports/'.View::e($id).'/cancel">'.$this->view()->csrf().'<button class="button button-secondary">Откажи</button></form></div>';
        }
        if (!in_array($status, ['queued','processing','posting','ready'], true) && $this->canImport() && $this->service()->sourcePath($id) !== null) {
            $body .= '<form method="post" action="/revenues/imports/'.View::e($id).'/reimport" class="inline-form">'.$this->view()->csrf().'<button class="button button-secondary">Повторно увези го овој извештај</button></form>'
                .'<p class="muted">Ја чита повторно истата датотека како нов увоз, со преглед. Уплатите што се во системот не се евидентираат двапати.</p>';
        }
        $body .= '</section>';

        if (!empty($summary['by_category'])) {
            $names = $this->categoryNames();
            $rows = '';
            uasort($summary['by_category'], static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
            foreach ($summary['by_category'] as $code => $row) $rows .= '<tr><th scope="row">'.View::e($names[$code] ?? $code).'</th><td class="num right">'.(int) $row['count'].'</td><td class="num right">'.View::e(View::money($row['amount'])).'</td></tr>';
            $warnings = '';
            foreach ((array) ($summary['warnings'] ?? []) as $code => $count) $warnings .= '<li>'.View::e(RevenueImport::WARNINGS[$code] ?? $code).': <strong>'.(int) $count.'</strong></li>';
            $body .= '<section class="card"><h2>Нови уплати според видот</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Вид</th><th class="num right">Уплата</th><th class="num right">Износ</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
                .($warnings !== '' ? '<h3>За проверка по увозот</h3><ul>'.$warnings.'</ul><p class="muted">Овие уплати се увезуваат; нивниот вид може да се промени на страницата на уплатата или со ново правило во „Видови приходи“.</p>' : '')
                .'</section>';
        }

        $tab = (string) $this->request->input('rows', $posted ? 'imported' : 'new');
        $tabs = $posted ? ['imported'=>['Увезени се', (int) ($summary['posted']['imported'] ?? 0)]] : ['new'=>[self::STATUS['new'], $counts['new']], 'existing'=>[self::STATUS['existing'], $counts['existing']]];
        $tabs += ['duplicate_in_file'=>[self::STATUS['duplicate_in_file'], $counts['duplicate_in_file']], 'invalid'=>[self::STATUS['invalid'], $counts['invalid']]];
        if (!isset($tabs[$tab])) $tab = (string) array_key_first($tabs);
        $nav = '<nav class="tabs" aria-label="Редови од извештајот">';
        foreach ($tabs as $key => [$name, $count]) $nav .= '<a href="?rows='.$key.'#rows"'.($key === $tab ? ' class="is-active" aria-current="page"' : '').'>'.View::e($name).' <span class="tabs__count">'.(int) $count.'</span></a>';
        $nav .= '</nav>';
        $pager = Pagination::fromRequest();
        $select = 'r.source_row_number,r.payment_date,r.amount,r.uniref,r.raw_uniref,r.payer_name,r.raw_payer,r.payer_type,r.identification_number,r.invoice_number,r.taxpayer_number,r.property_number,r.plate_number,r.case_reference,r.channel,r.warnings,r.raw_description,r.raw_payment_date,r.raw_amount,c.name category';
        $rows = $tab === 'imported'
            ? $pager->query($this->db, 'SELECT r.id,'.$select.',NULL errors FROM revenue_transactions r JOIN revenue_categories c ON c.id=r.category_id WHERE r.import_batch_id=:batch ORDER BY r.source_row_number', ['batch'=>$id])
            : $pager->query($this->db, 'SELECT NULL id,'.$select.',r.errors FROM revenue_import_rows r JOIN revenue_categories c ON c.id=r.category_id WHERE r.import_batch_id=:batch AND r.row_status=:status ORDER BY r.source_row_number', ['batch'=>$id, 'status'=>$tab]);
        $table = '';
        foreach ($rows as $row) {
            $notes = [];
            foreach (array_filter(explode(',', (string) ($row['errors'] ?? ''))) as $code) $notes[] = '<span class="status-badge status-cancelled">'.View::e(RevenueImport::ERRORS[$code] ?? $code).'</span>';
            foreach (array_filter(explode(',', (string) ($row['warnings'] ?? ''))) as $code) $notes[] = '<span class="status-badge status-draft">'.View::e(RevenueImport::WARNINGS[$code] ?? $code).'</span>';
            $date = $row['payment_date'] ? $this->displayDate((string) $row['payment_date']) : (string) $row['raw_payment_date'];
            $table .= '<tr><td>'.(int) $row['source_row_number'].'</td><td>'.($row['id'] ? '<a href="/revenues/'.(int) $row['id'].'">'.View::e($date).'</a>' : View::e($date)).'</td><td class="num right">'.View::e($row['amount'] !== null ? View::money($row['amount']) : (string) $row['raw_amount']).'</td><td>'.View::e($row['category']).'</td><td><code>'.View::e($row['uniref'] ?: $row['raw_uniref']).'</code></td><td>'.$this->payer($row).'</td><td>'.View::e($row['identification_number'] ?? '').'</td><td>'.View::e($row['invoice_number'] ?? '').'</td><td>'.View::e($row['plate_number'] ?? '').'</td><td>'.View::e($row['case_reference'] ?? '').'</td><td><small>'.View::e(mb_substr((string) $row['raw_description'], 0, 140)).'</small></td><td>'.implode(' ', $notes).'</td></tr>';
        }
        $body .= '<div id="rows">'.$nav.'<section class="card"><div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Ред</th><th>Датум</th><th class="num right">Износ</th><th>Вид</th><th>UNIREF</th><th>Уплатувач</th><th>Идентификациски бр.</th><th>Бр. на фактура</th><th>Регистарски таблички</th><th>Бр. на предмет</th><th>Опис</th><th>Белешки</th></tr></thead><tbody>'.($table ?: '<tr><td colspan="12">Нема редови.</td></tr>').'</tbody></table></div>'.$pager->nav().'</section></div>';
        $this->view()->render('Увоз на приходите', $body);
    }

    public function importConfirm(string $id): never
    {
        $this->requireImport();
        $service = $this->service();
        $service->startPosting($id);
        // Up to 300 000 rows: written in the background in chunks of 4 000; the import page follows the progress.
        $this->respondAndContinue('/revenues/imports/'.$id, 'Уплатите се евидентираат во позадина. Страницата се освежува сама кога ќе заврши.');
        $service->post($id);
        exit;
    }

    /** POST /revenues/imports/{id}/reimport: the stored report of an import is read again as a new import. */
    public function importReimport(string $id): never
    {
        $this->requireImport();
        $service = $this->service();
        if ($service->sourcePath($id) === null) throw new HttpException(404, 'Датотеката од овој увоз повеќе не се наоѓа на серверот.');
        $newId = Request::uuid();
        $this->respondAndContinue('/revenues/imports/'.$newId, 'Извештајот повторно се чита како нов увоз.');
        try { $service->reimport($id, $newId); } catch (\Throwable $exception) { $this->logger->write('error', 'revenue.reimport_failed', ['batch'=>$id, 'error'=>$exception->getMessage()]); }
        exit;
    }

    /** GET /revenues/maintenance: clear all revenues, optionally restoring them from one import. */
    public function maintenance(): never
    {
        $this->requireImport();
        $totals = $this->db->one('SELECT COUNT(*) payments,COALESCE(SUM(amount),0) amount,MIN(payment_date) date_from,MAX(payment_date) date_to FROM revenue_transactions') ?? [];
        $manual = (int) $this->db->scalar("SELECT COUNT(*) FROM revenue_transactions WHERE classification_method='manual'");
        $imports = $this->db->all("SELECT TOP 200 b.id,b.status,b.summary,b.created_at,u.name user_name,(SELECT COUNT(*) FROM revenue_transactions t WHERE t.import_batch_id=b.id) payments FROM import_batches b LEFT JOIN users u ON u.id=b.created_by WHERE b.type=:type AND b.status IN ('posted','cleared','unchanged','cancelled') ORDER BY b.created_at DESC", ['type'=>RevenueImport::TYPE]);
        $service = $this->service();
        $options = '<option value="">Не враќај ништо (само избриши)</option>';
        $rows = '';
        foreach ($imports as $import) {
            $summary = json_decode((string) $import['summary'], true) ?: [];
            $file = ($summary['options']['source_file_name'] ?? '') ?: 'Извештај';
            $available = $service->sourcePath((string) $import['id']) !== null;
            [$label, $class] = self::BATCH_STATUS[(string) $import['status']] ?? [(string) $import['status'], 'status-draft'];
            $period = isset($summary['date_from']) && $summary['date_from'] ? $this->displayDate((string) $summary['date_from']).' – '.$this->displayDate((string) $summary['date_to']) : '—';
            if ($available) $options .= '<option value="'.View::e($import['id']).'">'.View::e(substr((string) $import['created_at'], 0, 16).' · '.$file.' · '.(int) ($summary['rows'] ?? 0).' rreshta').'</option>';
            $rows .= '<tr><td><a href="/revenues/imports/'.View::e($import['id']).'">'.View::e($file).'</a></td><td>'.View::e(substr((string) $import['created_at'], 0, 16)).'</td><td>'.View::e($import['user_name'] ?? '—').'</td><td>'.View::e($period).'</td><td class="num right">'.(int) ($summary['rows'] ?? 0).'</td><td class="num right">'.(int) $import['payments'].'</td><td><span class="status-badge '.$class.'">'.View::e($label).'</span></td><td>'.($available ? 'Да' : '<span class="muted">Не</span>').'</td></tr>';
        }
        $operations = '';
        foreach ($this->db->all("SELECT TOP 10 b.id,b.status,b.summary,b.created_at,u.name user_name FROM import_batches b LEFT JOIN users u ON u.id=b.created_by WHERE b.type=:type ORDER BY b.created_at DESC", ['type'=>RevenueImport::CLEAR_TYPE]) as $op) {
            $summary = json_decode((string) $op['summary'], true) ?: [];
            $state = match ((string) $op['status']) { 'processing'=>'<span class="status-badge status-draft" data-import-status="/revenues/imports/'.View::e($op['id']).'/status">Во работа…</span>', 'posted'=>'<span class="status-badge status-closed">Извршено</span>', default=>'<span class="status-badge status-cancelled">Неуспешно</span>' };
            $deleted = isset($summary['deleted']) ? (int) $summary['deleted']['payments'].' уплати · '.View::money($summary['deleted']['amount']) : '—';
            $restored = !empty($summary['restored_batch']) ? '<a href="/revenues/imports/'.View::e($summary['restored_batch']).'">'.View::e(match ($summary['restore_status'] ?? '') { 'posted'=>'Вратено', 'unchanged'=>'Ништо ново', 'failed'=>'Враќањето не успеа', default=>'Види го увозот' }).'</a>' : '—';
            $operations .= '<tr><td>'.View::e(substr((string) $op['created_at'], 0, 16)).'</td><td>'.View::e($op['user_name'] ?? '—').'</td><td>'.View::e($deleted).'</td><td>'.$restored.'</td><td>'.View::e((string) ($summary['reason'] ?? '')).($summary['error'] ?? '' ? '<br><small class="text-danger">'.View::e((string) $summary['error']).'</small>' : '').'</td><td>'.$state.'</td></tr>';
        }
        $body = '<p><a class="button button-secondary" href="/revenues">Назад кон приходите</a></p>'
            .'<section class="card"><h2>Тековна состојба</h2><p><strong>'.number_format((int) ($totals['payments'] ?? 0), 0, '.', ',').'</strong> уплати · <strong>'.View::e(View::money($totals['amount'] ?? 0)).'</strong>'.(!empty($totals['date_from']) ? ' · од '.View::e($this->displayDate((string) $totals['date_from'])).' до '.View::e($this->displayDate((string) $totals['date_to'])) : '').'</p>'
            .($manual > 0 ? '<p class="muted">'.$manual.' уплати се класифицирани рачно; по бришењето оваа класификација се губи (правилата UNIREF остануваат).</p>' : '').'</section>'
            .'<section class="card danger-zone"><h2>Избриши ги сите приходи и врати од еден увоз</h2>'
            .'<p>Се бришат <strong>сите уплати</strong> од модулот „Приходи“. Увозите се чуваат во историјата со статус „Уплатите се избришани“, а нивните датотеки остануваат на серверот, па секој може повторно да се увезе. Видовите приходи и правилата не се допираат. Дејството се евидентира во ревизијата.</p>'
            .'<form method="post" action="/revenues/maintenance/clear" class="form-grid">'.$this->view()->csrf()
            .'<label class="wide">По бришењето, врати ги уплатите само од овој увоз<select name="restore_batch_id">'.$options.'</select><small class="field-hint">Избраниот извештај повторно се чита и автоматски се потврдува; во системот остануваат само неговите уплати.</small></label>'
            .'<label class="wide">Причина<textarea name="reason" maxlength="1000" required></textarea></label>'
            .'<label class="wide">За потврда, напишете <strong>ИЗБРИШИ СÈ</strong><input name="confirmation" autocomplete="off" required></label>'
            .'<button class="button button-danger">Избриши ги сите приходи</button></form></section>'
            .($operations !== '' ? '<section class="card"><h2>Претходни бришења</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Кога</th><th>Од</th><th>Избришани се</th><th>Враќање</th><th>Причина</th><th>Статус</th></tr></thead><tbody>'.$operations.'</tbody></table></div></section>' : '')
            .'<section class="card"><h2>Увози</h2><p class="muted">Секој увоз со датотека на серверот може повторно да се увезе од неговата страница („Повторно увези го овој извештај“).</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Датотека</th><th>Прикачено на</th><th>Од</th><th>Период</th><th class="num right">Редови</th><th class="num right">Уплати во системот</th><th>Статус</th><th>Датотека на серверот</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="8">Нема увози.</td></tr>').'</tbody></table></div></section>';
        $this->view()->render('Одржување на приходите', $body);
    }

    /** POST /revenues/maintenance/clear: checked here, carried out in the background (up to 300 000 payments). */
    public function maintenanceClear(): never
    {
        $this->requireImport();
        if (trim((string) $this->request->input('confirmation', '')) !== 'ИЗБРИШИ СÈ') throw new HttpException(422, 'Напишете точно ИЗБРИШИ СÈ за да го потврдите бришењето.');
        $reason = $this->requireField('reason', 1000);
        $restore = trim((string) $this->request->input('restore_batch_id', ''));
        if ($restore !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $restore)) throw new HttpException(422, 'Увозот за враќање не е валиден.');
        $service = $this->service();
        $operationId = Request::uuid();
        $service->startClear($operationId, $restore === '' ? null : $restore, $reason);
        $this->respondAndContinue('/revenues/maintenance', $restore === '' ? 'Бришењето на приходите се извршува во позадина.' : 'Бришењето и враќањето од избраниот увоз се извршуваат во позадина.');
        try { $service->clearAll($operationId); } catch (\Throwable $exception) { $this->logger->write('error', 'revenue.clear_failed', ['operation'=>$operationId, 'error'=>$exception->getMessage()]); }
        exit;
    }

    public function importCancel(string $id): never
    {
        $this->requireImport();
        $this->service()->cancel($id);
        $this->redirect('/revenues', 'Увозот е откажан; ниедна уплата не е евидентирана.');
    }

    /** GET /revenues/{id}: the source values next to what was read from them. */
    public function show(int $id): never
    {
        $row = $this->db->one('SELECT t.*,c.code category_code,c.name category,u.name classified_by_name FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.classified_by WHERE t.id=:id', ['id'=>$id]);
        if (!$row) throw new HttpException(404, 'Уплатата не е пронајдена.');
        $source = [
            ['Реден бр.', $row['raw_row_number']], ['Износ', $row['raw_amount']], ['Корисник', $row['raw_beneficiary']], ['Уплатувач', $row['raw_payer']],
            ['Група', $row['raw_revenue_group']], ['Опис', $row['raw_description']], ['Датум', $row['raw_payment_date']], ['UNIREF', $row['raw_uniref']],
        ];
        $extraction = json_decode((string) ($row['extraction'] ?? ''), true) ?: [];
        $how = static fn(string $field): string => match ($extraction[$field] ?? null) { 'label'=>' <small class="muted">(со ознака во описот)</small>', 'pattern'=>' <small class="muted">(според обликот на бројот – проверете)</small>', 'payer'=>' <small class="muted">(од колоната Уплатувач)</small>', 'description'=>' <small class="muted">(од описот)</small>', 'uniref'=>' <small class="muted">(од UNIREF)</small>', default=>'' };
        $read = [
            ['Датум на уплатата', $this->displayDate((string) $row['payment_date'])], ['Износ', View::money($row['amount'])],
            ['Група', ($row['revenue_group'] ?? '—').' · '.(RevenueReport::GROUPS[(string) $row['revenue_group']] ?? '—')],
            ['UNIREF', $row['uniref'] ? $row['uniref'].' · општина '.$row['uniref_municipality'].' · вид '.$row['uniref_type'].' · подвид '.$row['uniref_subtype'].' · референца '.$row['uniref_reference'].((int) $row['uniref_generic'] ? ' (заедничка референца на услугата, не на уплатувачот)' : '') : '—'],
            ['Уплатувач', ($row['payer_name'] ?? '—').' · '.(self::PAYER_TYPE[(string) $row['payer_type']] ?? '—'), 'payer_name'],
            ['Идентификациски бр.', $row['identification_number'] ? $row['identification_number'].' · '.(self::ID_TYPE[(string) $row['identification_type']] ?? '') : '—', 'identification_number'],
            ['Бр. на фактурата', $row['invoice_number'] ?? '—', 'invoice_number'], ['Бр. на даночниот обврзник', $row['taxpayer_number'] ?? '—', 'taxpayer_number'],
            ['Бр. на имотот / парцелата', $row['property_number'] ?? '—'], ['Регистарски таблички', $row['plate_number'] ?? '—'], ['Бр. на предметот / архивски број', $row['case_reference'] ?? '—'],
            ['Канал на плаќање', $row['channel'] ?? '—'], ['Вид на приходот', $row['category'].' · '.(self::METHOD[(string) $row['classification_method']] ?? '').($row['classified_by_name'] ? ' од '.$row['classified_by_name'] : '')],
        ];
        $table = static function (array $rows, ?callable $how = null): string {
            $html = '';
            foreach ($rows as $r) $html .= '<tr><th scope="row">'.View::e($r[0]).'</th><td>'.View::e((string) ($r[1] ?? '')).(isset($r[2]) && $how ? $how($r[2]) : '').'</td></tr>';
            return '<div class="table-wrap"><table class="data-table"><tbody>'.$html.'</tbody></table></div>';
        };
        $warnings = '';
        foreach (array_filter(explode(',', (string) $row['warnings'])) as $code) $warnings .= '<span class="status-badge status-draft">'.View::e(RevenueImport::WARNINGS[$code] ?? $code).'</span> ';
        $body = '<p><a class="button button-secondary" href="/revenues">Назад кон приходите</a> <a class="button button-secondary" href="/revenues/imports/'.View::e($row['import_batch_id']).'">Увоз</a></p>'
            .($warnings !== '' ? '<p>'.$warnings.'</p>' : '')
            .'<div class="grid"><section class="card"><h2>Како што се во извештајот</h2>'.$table($source).'</section><section class="card"><h2>Прочитани од извештајот</h2>'.$table($read, $how).'</section></div>';
        if ($this->canImport()) {
            $body .= '<section class="card"><h2>Промени го видот на приходот</h2><form method="post" action="/revenues/'.$id.'/category" class="form-grid">'.$this->view()->csrf()
                .'<label>Вид<select name="category_id" required>'.$this->categoryOptions((string) $row['category_code']).'</select></label><button class="primary">Зачувај</button></form>'
                .'<p class="muted">За сите уплати со ист вид UNIREF користете правило во <a href="/revenues/categories">Видови приходи</a>.</p></section>';
        }
        $this->view()->render('Уплата од '.$this->displayDate((string) $row['payment_date']).' · '.View::money($row['amount']), $body);
    }

    public function reclassify(int $id): never
    {
        $this->requireImport();
        $categoryId = $this->intField('category_id', 1, PHP_INT_MAX);
        if (!$this->db->scalar('SELECT 1 FROM revenue_categories WHERE id=:id AND active=1', ['id'=>$categoryId])) throw new HttpException(422, 'Видот не постои.');
        $before = $this->db->one('SELECT id,category_id,classification_method FROM revenue_transactions WHERE id=:id', ['id'=>$id]);
        if (!$before) throw new HttpException(404, 'Уплатата не е пронајдена.');
        $this->db->execute("UPDATE revenue_transactions SET category_id=:category,classification_method='manual',warnings=NULLIF(REPLACE(REPLACE(COALESCE(warnings,''),'unclassified,',''),'unclassified',''),''),classified_by=:user,classified_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id", ['category'=>$categoryId, 'user'=>$this->userId(), 'id'=>$id]);
        $this->audit->record($this->userId(), 'revenue.reclassified', 'RevenueTransaction', $id, $before, ['category_id'=>$categoryId, 'classification_method'=>'manual']);
        $this->redirect('/revenues/'.$id, 'Видот на приходот е променет.');
    }

    /** GET /revenues/categories: the types of revenue and the UNIREF rules that assign them. */
    public function categories(): never
    {
        $categories = $this->db->all('SELECT c.id,c.code,c.name,c.revenue_group,c.active,COUNT(t.id) payments,COALESCE(SUM(t.amount),0) amount FROM revenue_categories c LEFT JOIN revenue_transactions t ON t.category_id=c.id GROUP BY c.id,c.code,c.name,c.revenue_group,c.active,c.sort_order ORDER BY c.sort_order,c.name');
        $rows = '';
        foreach ($categories as $c) $rows .= '<tr><th scope="row">'.View::e($c['name']).'</th><td><code>'.View::e($c['code']).'</code></td><td>'.View::e($c['revenue_group'] ?? '—').'</td><td class="num right">'.(int) $c['payments'].'</td><td class="num right">'.View::e(View::money($c['amount'])).'</td></tr>';
        $rules = $this->db->all('SELECT r.id,r.uniref_prefix,r.keyword,r.priority,r.active,c.name category FROM revenue_category_rules r JOIN revenue_categories c ON c.id=r.category_id ORDER BY r.uniref_prefix,r.priority');
        $ruleRows = '';
        foreach ($rules as $r) {
            $toggle = $this->canImport() ? '<form method="post" action="/revenues/categories/rules/'.(int) $r['id'].'/toggle" class="inline-form">'.$this->view()->csrf().'<button class="button button-small button-secondary">'.((int) $r['active'] ? 'Деактивирај' : 'Активирај').'</button></form>' : '';
            $ruleRows .= '<tr><td><code>'.View::e($r['uniref_prefix']).'</code></td><td>'.View::e($r['keyword'] ?? '—').'</td><td>'.View::e($r['category']).'</td><td class="num right">'.(int) $r['priority'].'</td><td>'.((int) $r['active'] ? '<span class="status-badge status-active">Активно</span>' : '<span class="status-badge status-cancelled">Неактивно</span>').'</td><td>'.$toggle.'</td></tr>';
        }
        $body = '<p><a class="button button-secondary" href="/revenues">Назад кон приходите</a></p>'
            .'<section class="card"><h2>Како се определува видот</h2><p>UNIREF има 16 знаци: <code>PR</code> (komuna) · <code>B2</code> (вид на приходот) · <code>K</code> (подвид) · 10 цифри референца · 1 контролен знак. Видот се определува од правилото со најнизок приоритет што одговара на почетокот на видот на UNIREF (на пр. <code>F</code> за сите <code>F1…FX</code>) и, кога е зададен, со клучниот збор во описот или кај уплатувачот. Кога ниедно правило не одговара, се користи групата (10 → данок на имот, 20 → здравство, 30 → образование).</p></section>'
            .'<section class="card"><h2>Видови приходи</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Вид</th><th>Шифра</th><th>Група</th><th class="num right">Уплата</th><th class="num right">Износ</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>'
            .'<section class="card"><h2>Правила според UNIREF</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Вид UNIREF</th><th>Клучен збор</th><th>Вид на приходот</th><th class="num right">Приоритет</th><th>Статус</th><th></th></tr></thead><tbody>'.$ruleRows.'</tbody></table></div></section>';
        if ($this->canImport()) {
            $groups = '<option value="">Без група</option>';
            foreach (RevenueReport::GROUPS as $code => $label) $groups .= '<option value="'.$code.'">'.$code.' · '.View::e($label).'</option>';
            $body .= '<section class="card"><h2>Ново правило</h2><form method="post" action="/revenues/categories/rules" class="form-grid">'.$this->view()->csrf()
                .'<label>Вид UNIREF (1–2 знаци по PR)<input name="uniref_prefix" maxlength="2" pattern="[A-Za-z0-9]{1,2}" required placeholder="на пр. B2 или F"></label>'
                .'<label>Клучен збор (опционално)<input name="keyword" maxlength="100" placeholder="на пр. KUROR"></label>'
                .'<label>Вид на приходот<select name="category_id" required>'.$this->categoryOptions(null).'</select></label>'
                .'<label>Приоритет<input type="number" name="priority" min="1" max="999" value="50" required></label>'
                .'<p class="wide muted">Правилото се применува за идните увози и за постојните уплати што сѐ уште не се класифицирани според UNIREF или рачно.</p><button class="primary">Додај правило</button></form></section>'
                .'<section class="card"><h2>Нов вид приходи</h2><form method="post" action="/revenues/categories" class="form-grid">'.$this->view()->csrf()
                .'<label>Назив<input name="name" maxlength="200" required></label><label>Шифра<input name="code" maxlength="40" pattern="[a-z0-9_]{3,40}" required placeholder="на пр. parking_permits"></label><label>Група<select name="revenue_group">'.$groups.'</select></label><button class="primary">Додај вид</button></form></section>';
        }
        $this->view()->render('Видови приходи', $body);
    }

    public function ruleCreate(): never
    {
        $this->requireImport();
        $prefix = strtoupper($this->requireField('uniref_prefix', 2));
        if (!preg_match('/^[A-Z0-9]{1,2}$/', $prefix)) throw new HttpException(422, 'Видот UNIREF мора да има 1 или 2 букви/цифри.');
        $keyword = trim((string) $this->request->input('keyword', ''));
        if (mb_strlen($keyword) > 100) throw new HttpException(422, 'Клучниот збор е предолг.');
        $keyword = $keyword === '' ? null : mb_strtoupper($keyword);
        $categoryId = $this->intField('category_id', 1, PHP_INT_MAX);
        $priority = $this->intField('priority', 1, 999);
        if (!$this->db->scalar('SELECT 1 FROM revenue_categories WHERE id=:id AND active=1', ['id'=>$categoryId])) throw new HttpException(422, 'Видот не постои.');
        $applied = $this->db->transaction(function () use ($prefix, $keyword, $categoryId, $priority): int {
            $ruleId = $this->db->insertId('INSERT INTO revenue_category_rules(uniref_prefix,keyword,category_id,priority,active,created_by,created_at) VALUES(:prefix,:keyword,:category,:priority,1,:user,SYSUTCDATETIME())', ['prefix'=>$prefix, 'keyword'=>$keyword, 'category'=>$categoryId, 'priority'=>$priority, 'user'=>$this->userId()]);
            $applied = $this->service()->applyRule($prefix, $keyword, $categoryId);
            $this->audit->record($this->userId(), 'revenue_rule.created', 'RevenueCategoryRule', $ruleId, [], ['uniref_prefix'=>$prefix, 'keyword'=>$keyword, 'category_id'=>$categoryId, 'priority'=>$priority, 'applied_to'=>$applied]);
            return $applied;
        });
        $this->redirect('/revenues/categories', 'Правилото е додадено'.($applied ? ' и е применето за '.$applied.' некласифицирани уплати.' : '.'));
    }

    public function ruleToggle(int $id): never
    {
        $this->requireImport();
        $before = $this->db->one('SELECT id,active FROM revenue_category_rules WHERE id=:id', ['id'=>$id]);
        if (!$before) throw new HttpException(404, 'Правилото не е пронајдено.');
        $this->db->execute('UPDATE revenue_category_rules SET active=:active WHERE id=:id', ['active'=>(int) $before['active'] ? 0 : 1, 'id'=>$id]);
        $this->audit->record($this->userId(), 'revenue_rule.toggled', 'RevenueCategoryRule', $id, $before, ['active'=>(int) $before['active'] ? 0 : 1]);
        $this->redirect('/revenues/categories', (int) $before['active'] ? 'Правилото е деактивирано (важи за идните увози).' : 'Правилото е активирано.');
    }

    public function categoryCreate(): never
    {
        $this->requireImport();
        $name = $this->requireField('name', 200);
        $code = $this->requireField('code', 40);
        if (!preg_match('/^[a-z0-9_]{3,40}$/', $code)) throw new HttpException(422, 'Шифрата дозволува само мали латинични букви, цифри и _ (3–40).');
        $group = (string) $this->request->input('revenue_group', '');
        if ($group !== '' && !isset(RevenueReport::GROUPS[$group])) throw new HttpException(422, 'Групата не е валидна.');
        if ($this->db->scalar('SELECT 1 FROM revenue_categories WHERE code=:code', ['code'=>$code])) throw new HttpException(422, 'Оваа шифра постои.');
        $id = $this->db->insertId('INSERT INTO revenue_categories(code,name,revenue_group,sort_order,active,created_at,updated_at) VALUES(:code,:name,:group,(SELECT COALESCE(MAX(sort_order),0)+10 FROM revenue_categories),1,SYSUTCDATETIME(),SYSUTCDATETIME())', ['code'=>$code, 'name'=>$name, 'group'=>$group === '' ? null : $group]);
        $this->audit->record($this->userId(), 'revenue_category.created', 'RevenueCategory', $id, [], ['code'=>$code, 'name'=>$name, 'revenue_group'=>$group]);
        $this->redirect('/revenues/categories', 'Вид „'.$name.'“ е додаден. Додајте UNIREF правило за да му се доделат уплатите.');
    }

    /** GET /revenues.csv|xlsx with the filters of the page: every source value and every value read from it. */
    public function export(string $format): never
    {
        [$where, $params] = $this->filters();
        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id WHERE '.$where, $params);
        if ($count > 100000) throw new HttpException(422, 'Извозот е ограничен на 100 000 уплати; стеснете го периодот.');
        $rows = $this->db->all('SELECT CONVERT(varchar(10),t.payment_date,23) payment_date,t.amount,t.revenue_group,c.name category,t.uniref,t.uniref_type,t.payer_name,t.payer_type,t.identification_number,t.invoice_number,t.taxpayer_number,t.property_number,t.plate_number,t.case_reference,t.channel,t.classification_method,t.warnings,t.raw_row_number,t.raw_amount,t.raw_beneficiary,t.raw_payer,t.raw_revenue_group,t.raw_description,t.raw_payment_date,t.raw_uniref FROM revenue_transactions t JOIN revenue_categories c ON c.id=t.category_id WHERE '.$where.' ORDER BY t.payment_date,t.id', $params);
        $headers = ['payment_date'=>'Датум','amount'=>'Износ','revenue_group'=>'Група','category'=>'Вид на приходот','uniref'=>'UNIREF','uniref_type'=>'Вид UNIREF','payer_name'=>'Уплатувач','payer_type'=>'Вид на уплатувачот','identification_number'=>'Идентификациски бр.','invoice_number'=>'Бр. на фактура','taxpayer_number'=>'Бр. на даночен обврзник','property_number'=>'Бр. на имот','plate_number'=>'Регистарски таблички','case_reference'=>'Бр. на предмет','channel'=>'Канал','classification_method'=>'Класификација','warnings'=>'Белешки',
            'raw_row_number'=>'Извор: Бр.','raw_amount'=>'Извор: Износ','raw_beneficiary'=>'Извор: Корисник','raw_payer'=>'Извор: Уплатувач','raw_revenue_group'=>'Извор: Група','raw_description'=>'Извор: Опис','raw_payment_date'=>'Извор: Датум','raw_uniref'=>'Извор: UNIREF'];
        $this->audit->record($this->userId(), 'revenue.exported', 'RevenueTransaction', null, [], ['format'=>$format, 'rows'=>count($rows), 'filters'=>array_diff_key($_GET, ['_csrf'=>1])]);
        $export = new TabularExport(); $filename = 'e-kabineti-te-hyrat-'.date('Ymd-His');
        if ($format === 'csv') $export->csv($filename, $headers, $rows);
        $export->xlsx($filename, [['name'=>'Приходи', 'headers'=>$headers, 'rows'=>$rows]]);
    }

    /** Advanced filters: request name => [label, column, kind]. */
    private const ADVANCED = [
        'payer'=>['Уплатувач (име)', 't.payer_name', 'like'],
        'identification'=>['Идентификациски бр.', 't.identification_number', 'prefix'],
        'invoice'=>['Бр. на фактурата', 't.invoice_number', 'like'],
        'taxpayer'=>['Бр. на даночниот обврзник', 't.taxpayer_number', 'prefix'],
        'property'=>['Бр. на имотот', 't.property_number', 'like'],
        'plate'=>['Регистарски таблички', 't.plate_number', 'plate'],
        'case_ref'=>['Бр. на предмет', 't.case_reference', 'like'],
        'uniref'=>['UNIREF', 't.uniref', 'like'],
        'uniref_type'=>['Вид UNIREF', 't.uniref_type', 'code'],
        'payer_type'=>['Вид на уплатувачот', 't.payer_type', 'choice'],
        'method'=>['Класификација', 't.classification_method', 'choice'],
        'warning'=>['Белешка', 't.warnings', 'warning'],
        'batch'=>['Увоз', 't.import_batch_id', 'uuid'],
        'amount_min'=>['Износ од', 't.amount', 'min'],
        'amount_max'=>['Износ до', 't.amount', 'max'],
    ];
    private const SORTS = ['date_desc'=>['Датум (најнов)', 't.payment_date DESC,t.id DESC'], 'date_asc'=>['Датум (најстар)', 't.payment_date ASC,t.id ASC'], 'amount_desc'=>['Износ (најголем)', 't.amount DESC,t.id DESC'], 'amount_asc'=>['Износ (најмал)', 't.amount ASC,t.id ASC']];

    /** WHERE clause of the page filters (period, group, type, channel, search, review and the advanced filters). */
    private function filters(): array
    {
        $where = ['1=1']; $params = []; $filters = [];
        foreach (['from'=>'>=', 'to'=>'<='] as $name => $operator) {
            $value = trim((string) ($_GET[$name] ?? ''));
            if ($value === '') continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) continue;
            $where[] = 't.payment_date'.$operator.':'.$name; $params[$name] = $value; $filters[$name] = $value;
        }
        $group = (string) ($_GET['group'] ?? '');
        if (isset(RevenueReport::GROUPS[$group])) { $where[] = 't.revenue_group=:group'; $params['group'] = $group; $filters['group'] = $group; }
        $category = trim((string) ($_GET['category'] ?? ''));
        if ($category !== '' && preg_match('/^[a-z0-9_]{3,40}$/', $category)) { $where[] = 'c.code=:category'; $params['category'] = $category; $filters['category'] = $category; }
        $channel = trim((string) ($_GET['channel'] ?? ''));
        if ($channel !== '' && mb_strlen($channel) <= 30) { $where[] = 't.channel=:channel'; $params['channel'] = $channel; $filters['channel'] = $channel; }
        if (($_GET['review'] ?? '') === '1') { $where[] = 't.warnings IS NOT NULL'; $filters['review'] = '1'; }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '' && mb_strlen($q) <= 100) {
            $filters['q'] = $q;
            $compact = strtoupper((string) preg_replace('/[\s-]+/', '', $q));
            $where[] = '(t.uniref LIKE :q1 OR t.payer_name LIKE :q2 OR t.raw_payer LIKE :q3 OR t.raw_description LIKE :q4 OR t.identification_number LIKE :q5 OR t.invoice_number LIKE :q6 OR t.taxpayer_number LIKE :q7 OR t.case_reference LIKE :q8 OR REPLACE(t.plate_number,\'-\',\'\') LIKE :q9)';
            foreach (range(1, 8) as $i) $params['q'.$i] = '%'.$q.'%';
            $params['q9'] = '%'.$compact.'%';
        }
        foreach (self::ADVANCED as $name => [, $column, $kind]) {
            $value = trim((string) ($_GET[$name] ?? ''));
            if ($value === '' || mb_strlen($value) > 100) continue;
            $param = 'f_'.$name;
            switch ($kind) {
                case 'like': $where[] = $column.' LIKE :'.$param; $params[$param] = '%'.$value.'%'; break;
                case 'prefix': $where[] = $column.' LIKE :'.$param; $params[$param] = $value.'%'; break;
                case 'plate': $where[] = 'REPLACE('.$column.',\'-\',\'\') LIKE :'.$param; $params[$param] = '%'.strtoupper((string) preg_replace('/[\s-]+/', '', $value)).'%'; break;
                case 'code': if (!preg_match('/^[A-Za-z0-9]{1,2}$/', $value)) continue 2; $value = strtoupper($value); $where[] = $column.' LIKE :'.$param; $params[$param] = $value.'%'; break;
                case 'choice':
                    $allowed = $name === 'payer_type' ? ['individual','business','unknown'] : ['uniref','keyword','group','manual','none'];
                    if (!in_array($value, $allowed, true)) continue 2;
                    $where[] = $column.'=:'.$param; $params[$param] = $value; break;
                case 'warning': if (!isset(RevenueImport::WARNINGS[$value])) continue 2; $where[] = $column.' LIKE :'.$param; $params[$param] = '%'.$value.'%'; break;
                case 'uuid': if (!preg_match('/^[0-9a-f-]{36}$/i', $value)) continue 2; $where[] = $column.'=:'.$param; $params[$param] = $value; break;
                case 'min': case 'max':
                    $amount = RevenueReport::normalizeAmount($value);
                    if ($amount === '') continue 2;
                    $where[] = $column.($kind === 'min' ? '>=' : '<=').':'.$param; $params[$param] = $amount; $value = $amount; break;
            }
            $filters[$name] = $value;
        }
        $sort = (string) ($_GET['sort'] ?? '');
        if (isset(self::SORTS[$sort]) && $sort !== 'date_desc') $filters['sort'] = $sort;
        return [implode(' AND ', $where), $params, $filters];
    }

    private function orderBy(array $filters): string
    {
        return self::SORTS[$filters['sort'] ?? 'date_desc'][1];
    }

    private function filterForm(array $filters): string
    {
        $categories = '<option value="">Сите видови</option>';
        foreach ($this->db->all('SELECT code,name,revenue_group FROM revenue_categories WHERE active=1 ORDER BY revenue_group,sort_order,name') as $c) {
            if (isset($filters['group']) && $c['revenue_group'] !== null && (string) $c['revenue_group'] !== $filters['group']) continue;
            $categories .= '<option value="'.View::e($c['code']).'"'.(($filters['category'] ?? '') === $c['code'] ? ' selected' : '').'>'.View::e(($c['revenue_group'] ? $c['revenue_group'].' · ' : '').$c['name']).'</option>';
        }
        $groups = '<option value="">Сите групи</option>';
        foreach (RevenueReport::GROUPS as $code => $label) $groups .= '<option value="'.$code.'"'.(($filters['group'] ?? '') === (string) $code ? ' selected' : '').'>'.$code.' · '.View::e($label).'</option>';
        $channels = '<option value="">Сите канали</option>';
        foreach ($this->db->all('SELECT DISTINCT channel FROM revenue_transactions WHERE channel IS NOT NULL ORDER BY channel') as $c) $channels .= '<option'.(($filters['channel'] ?? '') === $c['channel'] ? ' selected' : '').'>'.View::e($c['channel']).'</option>';
        $select = static function (string $name, array $options, array $filters): string {
            $html = '<option value="">Сите</option>';
            foreach ($options as $value => $label) $html .= '<option value="'.View::e($value).'"'.(($filters[$name] ?? '') === (string) $value ? ' selected' : '').'>'.View::e($label).'</option>';
            return '<select name="'.$name.'">'.$html.'</select>';
        };
        $batches = [];
        foreach ($this->db->all("SELECT TOP 100 id,summary,created_at FROM import_batches WHERE type=:type AND status='posted' ORDER BY created_at DESC", ['type'=>RevenueImport::TYPE]) as $b) {
            $summary = json_decode((string) $b['summary'], true) ?: [];
            $batches[(string) $b['id']] = substr((string) $b['created_at'], 0, 10).' · '.(($summary['options']['source_file_name'] ?? '') ?: 'Извештај');
        }
        $advanced = '';
        $text = static fn(string $name, string $placeholder = ''): string => '<input name="'.$name.'" value="'.View::e($filters[$name] ?? '').'" maxlength="100"'.($placeholder !== '' ? ' placeholder="'.View::e($placeholder).'"' : '').'>';
        foreach (['payer'=>'', 'identification'=>'p.sh. 1012…', 'invoice'=>'', 'taxpayer'=>'p.sh. 219…', 'property'=>'', 'plate'=>'01-234-AB', 'case_ref'=>'11-942/03-…', 'uniref'=>'PRB2K…', 'uniref_type'=>'B2, F…'] as $name => $placeholder) $advanced .= '<label>'.View::e(self::ADVANCED[$name][0]).$text($name, $placeholder).'</label>';
        $advanced .= '<label>Износ од (МКД)<input name="amount_min" inputmode="decimal" value="'.View::e($filters['amount_min'] ?? '').'"></label><label>Износ до (МКД)<input name="amount_max" inputmode="decimal" value="'.View::e($filters['amount_max'] ?? '').'"></label>'
            .'<label>Вид на уплатувачот'.$select('payer_type', ['individual'=>'Физичко лице', 'business'=>'Biznes / institucion', 'unknown'=>'Непознат'], $filters).'</label>'
            .'<label>Класификација'.$select('method', self::METHOD, $filters).'</label>'
            .'<label>Белешка'.$select('warning', RevenueImport::WARNINGS, $filters).'</label>'
            .'<label>Увоз'.$select('batch', $batches, $filters).'</label>';
        $advancedActive = array_intersect_key($filters, self::ADVANCED) !== [];
        $sorts = '';
        foreach (self::SORTS as $value => [$label]) $sorts .= '<option value="'.$value.'"'.(($filters['sort'] ?? 'date_desc') === $value ? ' selected' : '').'>'.View::e($label).'</option>';
        $today = new \DateTimeImmutable('today');
        $quick = ['Денес'=>[$today->format('Y-m-d'), $today->format('Y-m-d')], 'Овој месец'=>[$today->format('Y-m-01'), $today->format('Y-m-t')], 'Оваа година'=>[$today->format('Y-01-01'), $today->format('Y-12-31')]];
        $chips = '';
        foreach ($quick as $label => [$a, $b]) $chips .= '<a class="chip'.(($filters['from'] ?? '') === $a && ($filters['to'] ?? '') === $b ? ' is-active' : '').'" href="?'.View::e(http_build_query(['from'=>$a, 'to'=>$b] + $filters)).'">'.$label.'</a>';
        $chips .= '<a class="chip'.(!isset($filters['from']) && !isset($filters['to']) ? ' is-active' : '').'" href="?'.View::e(http_build_query(array_diff_key($filters, ['from'=>1, 'to'=>1]))).'">Целиот период</a>';
        return '<form method="get" class="card filter-card revenue-filters">'
            .'<div class="filter-bar"><label>Пребарај<input name="q" value="'.View::e($filters['q'] ?? '').'" placeholder="Име, UNIREF, ЕМБГ, фактура, регистарска табличка…"></label>'
            .'<label>Од<input type="date" name="from" value="'.View::e($filters['from'] ?? '').'"></label><label>До<input type="date" name="to" value="'.View::e($filters['to'] ?? '').'"></label>'
            .'<label>Група<select name="group">'.$groups.'</select></label><label>Вид<select name="category">'.$categories.'</select></label><label>Канал<select name="channel">'.$channels.'</select></label>'
            .'<label>Редослед<select name="sort">'.$sorts.'</select></label>'
            .'<label class="inline"><input type="checkbox" name="review" value="1"'.(isset($filters['review']) ? ' checked' : '').'> Само за проверка</label></div>'
            .'<details class="filter-advanced"'.($advancedActive ? ' open' : '').'><summary>Напредни филтри'.($advancedActive ? ' ('.count(array_intersect_key($filters, self::ADVANCED)).' активни)' : '').'</summary><div class="form-grid">'.$advanced.'</div></details>'
            .'<div class="form-actions"><button class="primary">Филтрирај</button><a class="button button-secondary" href="/revenues">Исчисти сè</a></div></form>'
            .$this->activeFilters($filters)
            .'<div class="chips" aria-label="Период">'.$chips.'</div>';
    }

    /** One chip per active filter; × removes only that filter. */
    private function activeFilters(array $filters): string
    {
        $labels = ['q'=>'Пребарување', 'from'=>'Од', 'to'=>'До', 'group'=>'Група', 'category'=>'Вид', 'channel'=>'Канал', 'review'=>'За проверка', 'sort'=>'Редослед'];
        $names = $filters !== [] ? $this->categoryNames() : [];
        $chips = '';
        foreach ($filters as $name => $value) {
            $label = $labels[$name] ?? (self::ADVANCED[$name][0] ?? $name);
            $shown = match ($name) {
                'group' => $value.' · '.(RevenueReport::GROUPS[$value] ?? ''),
                'category' => $names[$value] ?? $value,
                'review' => 'po',
                'sort' => self::SORTS[$value][0] ?? $value,
                'from', 'to' => $this->displayDate((string) $value),
                'payer_type' => ['individual'=>'Физичко лице', 'business'=>'Biznes / institucion', 'unknown'=>'Непознат'][$value] ?? $value,
                'method' => self::METHOD[$value] ?? $value,
                'warning' => RevenueImport::WARNINGS[$value] ?? $value,
                'batch' => 'избран',
                default => (string) $value,
            };
            $chips .= '<a class="chip is-active" href="?'.View::e(http_build_query(array_diff_key($filters, [$name=>1]))).'" title="Отстрани го овој филтер">'.View::e($label.': '.$shown).' <span aria-hidden="true">×</span></a>';
        }
        return $chips === '' ? '' : '<div class="chips active-filters" aria-label="Активни филтри">'.$chips.'</div>';
    }

    /** Drill-down trail: all revenues › group › type, each step a link to that level (other filters are kept). */
    private function drillPath(array $filters): string
    {
        $group = $filters['group'] ?? null;
        $category = $filters['category'] ?? null;
        if ($category !== null && $group === null) {
            $found = $this->db->scalar('SELECT revenue_group FROM revenue_categories WHERE code=:code', ['code'=>$category]);
            if ($found !== false && $found !== null) $group = (string) $found;
        }
        $rest = array_diff_key($filters, ['group'=>1, 'category'=>1]);
        $steps = ['<a href="?'.View::e(http_build_query($rest)).'">Сите приходи</a>'];
        if ($group !== null) $steps[] = $category === null ? '<span aria-current="page">'.View::e($group.' · '.(RevenueReport::GROUPS[$group] ?? '')).'</span>' : '<a href="?'.View::e(http_build_query(['group'=>$group] + $rest)).'">'.View::e($group.' · '.(RevenueReport::GROUPS[$group] ?? '')).'</a>';
        if ($category !== null) $steps[] = '<span aria-current="page">'.View::e($this->categoryNames()[$category] ?? $category).'</span>';
        $hint = $group === null ? 'Изберете група подолу за да ја разложите по видови и уплати.' : ($category === null ? 'Изберете вид за да го разложите до уплатите.' : 'Уплатите од овој вид се на дното на страницата; можете дополнително да ги филтрирате.');
        return '<nav class="drill-path" aria-label="Разложување"><ol><li>'.implode('</li><li>', $steps).'</li></ol><p class="muted">'.View::e($hint).'</p></nav>';
    }

    /** Group or type selected: the same payments by UNIREF type, by channel and by payer type. */
    private function breakdowns(string $from, array $params, array $filters, float $total): string
    {
        $table = function (string $title, string $column, string $filter, array $labels = []) use ($from, $params, $filters, $total): string {
            $rows = '';
            foreach ($this->db->all("SELECT {$column} k,COUNT(*) payments,SUM(t.amount) amount ".$from." GROUP BY {$column} ORDER BY SUM(t.amount) DESC", $params) as $row) {
                $key = (string) ($row['k'] ?? '');
                $share = $total > 0 ? round((float) $row['amount'] / $total * 100, 1) : 0;
                $name = $key === '' ? '—' : ($labels[$key] ?? $key);
                $link = $key === '' ? View::e($name) : '<a href="?'.View::e(http_build_query([$filter=>$key] + $filters)).'#payments">'.View::e($name).'</a>';
                $rows .= '<tr><th scope="row">'.$link.'</th><td class="num right">'.(int) $row['payments'].'</td><td class="num right">'.View::e(View::money($row['amount'])).'</td><td class="progress-cell"><progress class="progress-bar" max="100" value="'.$share.'" aria-hidden="true"></progress><span>'.$share.'%</span></td></tr>';
            }
            return '<section class="card"><h2>'.View::e($title).'</h2><div class="table-wrap"><table class="data-table"><thead><tr><th></th><th class="num right">Уплата</th><th class="num right">Износ</th><th>Дел</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="4">—</td></tr>').'</tbody></table></div></section>';
        };
        return '<div class="grid">'
            .$table('Според видот UNIREF', 't.uniref_type', 'uniref_type')
            .$table('Според каналот на плаќање', 't.channel', 'channel')
            .$table('Според уплатувачот', 't.payer_type', 'payer_type', ['individual'=>'Физичко лице', 'business'=>'Biznes / institucion', 'unknown'=>'Непознат'])
            .'</div>';
    }

    private function paymentsHeading(array $filters): string
    {
        if (isset($filters['category'])) return 'Уплати · '.($this->categoryNames()[$filters['category']] ?? $filters['category']);
        if (isset($filters['group'])) return 'Уплати од групата '.$filters['group'].' · '.(RevenueReport::GROUPS[$filters['group']] ?? '');
        return 'Уплати';
    }

    private function importDialog(bool $open): string
    {
        return '<dialog class="modal" id="revenue-import" aria-labelledby="revenue-import-title" data-dialog'.($open ? ' data-open-on-load' : '').'>'
            .'<header class="modal__header"><div><p class="eyebrow">Приходи</p><h2 id="revenue-import-title">Увези дневен извештај</h2></div><button type="button" class="icon-button" data-modal-close aria-label="Затвори"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>'
            .$this->importFields(true).'</dialog>';
    }

    private function importFields(bool $dialog): string
    {
        $help = '<p class="wide muted">Извештај од трезорската сметка (XLSX, XLS или CSV), една уплата по ред: Бр · Износ · Корисник · Уплатувач · Група · Опис · Датум · UNIREF. Сите вредности се чуваат какви што се; од нив се читаат UNIREF-от, уплатувачот, ЕМБГ или ЕДБ, бројот на фактурата, на даночниот обврзник, на имотот, регистарските таблички, бројот на предметот и видот на приходот.</p>'
            .'<p class="wide muted">Пред увозот погледнете го прегледот. Се увезуваат само новите уплати со валиден датум и износ; уплатите што се во системот (истиот извештај или извештаи што се преклопуваат) не се евидентираат повторно.</p>';
        $duplicates = '<fieldset class="segmented wide"><legend>Идентични редови во истата датотека</legend><div class="segmented__options">'
            .'<label class="segmented__option"><input type="radio" name="duplicates" value="once" checked><span>Увези само еднаш</span></label>'
            .'<label class="segmented__option"><input type="radio" name="duplicates" value="separate"><span>Третирај ги како посебни уплати</span></label></div>'
            .'<small class="field-hint">Некои мали уплати се повторуваат со исти податоци (на пр. повеќе катастарски уверенија од исто лице на ист ден). Изберете „посебни“ само кога банката ги евидентирала како одделни трансакции.</small></fieldset>';
        $fields = EvidenceUi::dropzone('file', false, true, 'XLSX, XLS или CSV · до 300 000 редови (64 MB)', 'Дневен извештај', '.xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv').$duplicates.$help;
        if (!$dialog) return '<form method="post" action="/revenues/import" enctype="multipart/form-data" class="form-grid">'.$this->view()->csrf().$fields.'<button class="primary">Валидирај го извештајот</button></form>';
        return '<form method="post" action="/revenues/import" enctype="multipart/form-data" class="modal__form">'.$this->view()->csrf()
            .'<div class="modal__body form-grid">'.$fields.'</div>'
            .'<footer class="modal__footer"><span class="modal__keys muted">Esc затвора</span><div class="inline-actions"><button type="button" class="button button-secondary" data-modal-close>Откажи</button><button class="primary">Валидирај го извештајот</button></div></footer></form>';
    }

    private function paymentRows(array $payments): string
    {
        $html = '';
        foreach ($payments as $row) {
            $flag = $row['warnings'] ? ' <span class="status-badge status-draft" title="'.View::e(implode(', ', array_map(static fn(string $c): string => RevenueImport::WARNINGS[$c] ?? $c, explode(',', (string) $row['warnings'])))).'">!</span>' : '';
            $html .= '<tr><td><a href="/revenues/'.(int) $row['id'].'">'.View::e($this->displayDate((string) $row['payment_date'])).'</a></td><td class="num right"><strong>'.View::e(View::money($row['amount'])).'</strong></td><td>'.View::e($row['category']).$flag.'</td><td><code>'.View::e($row['uniref'] ?: $row['raw_uniref']).'</code></td><td>'.$this->payer($row).'</td><td>'.View::e($row['identification_number'] ?? '').'</td><td>'.View::e($row['invoice_number'] ?? '').'</td><td>'.View::e(trim(($row['taxpayer_number'] ?? '').' '.($row['property_number'] ?? ''))).'</td><td>'.View::e($row['plate_number'] ?? '').'</td><td>'.View::e($row['case_reference'] ?? '').'</td><td>'.View::e($row['channel'] ?? '').'</td></tr>';
        }
        return $html ?: '<tr><td colspan="11">Нема уплати. '.($this->canImport() ? 'Увезете го дневниот извештај.' : '').'</td></tr>';
    }

    private function payer(array $row): string
    {
        $name = (string) ($row['payer_name'] ?? '');
        $raw = (string) ($row['raw_payer'] ?? '');
        $type = ($row['payer_type'] ?? '') === 'business' ? ' <small class="muted">biznes</small>' : '';
        if ($name === '') return '<span class="muted">'.View::e($raw ?: '—').'</span>';
        return View::e($name).$type.($raw !== '' && mb_strtoupper($raw) !== mb_strtoupper($name) ? '<br><small class="muted">'.View::e(mb_substr($raw, 0, 60)).'</small>' : '');
    }

    private function recentImports(): string
    {
        $pager = Pagination::fromRequest('imports');
        $rows = $pager->query($this->db, 'SELECT b.id,b.status,b.summary,b.created_at,u.name user_name FROM import_batches b LEFT JOIN users u ON u.id=b.created_by WHERE b.type=:type ORDER BY b.created_at DESC', ['type'=>RevenueImport::TYPE]);
        $html = '';
        foreach ($rows as $row) {
            $summary = json_decode((string) ($row['summary'] ?? '{}'), true) ?: [];
            [$label, $class] = self::BATCH_STATUS[(string) $row['status']] ?? [(string) $row['status'], 'status-draft'];
            $counts = (array) ($summary['counts'] ?? []);
            $imported = isset($summary['posted']) ? (int) $summary['posted']['imported'] : null;
            $html .= '<tr><td><a href="/revenues/imports/'.View::e($row['id']).'">'.View::e(($summary['options']['source_file_name'] ?? '') ?: 'Извештај').'</a></td><td>'.View::e(substr((string) $row['created_at'], 0, 16)).'</td><td>'.View::e($row['user_name'] ?? '—').'</td><td class="num right">'.(int) ($summary['rows'] ?? 0).'</td><td class="num right">'.($imported ?? (int) ($counts['new'] ?? 0)).'</td><td class="num right">'.(int) ($counts['existing'] ?? 0).'</td><td class="num right">'.(int) ($counts['duplicate_in_file'] ?? 0).'</td><td class="num right">'.(int) ($counts['invalid'] ?? 0).'</td><td><span class="status-badge '.$class.'">'.View::e($label).'</span></td></tr>';
        }
        if ($html === '') return '';
        return '<section class="card"><h2>Увози на дневниот извештај</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Датотека</th><th>Прикачено на</th><th>Од</th><th class="num right">Редови</th><th class="num right">Нови / увезени</th><th class="num right">Постоеја</th><th class="num right">Повторени</th><th class="num right">Со грешки</th><th>Статус</th></tr></thead><tbody>'.$html.'</tbody></table></div>'.$pager->nav().'</section>';
    }

    /**
     * Sends the redirect and closes the connection (PHP-FPM), then lets the request continue: the session is written and
     * released first, the work may take minutes and is not stopped if the browser goes away.
     */
    private function respondAndContinue(string $location, string $message): void
    {
        $_SESSION['_flash'] = $message;
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(900);
        header('Location: '.$location, true, 303);
        header('Content-Length: 0');
        header('Connection: close');
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
    }

    private function batch(string $id, string $type = RevenueImport::TYPE): array
    {
        $batch = $this->db->one('SELECT b.*,u.name user_name FROM import_batches b LEFT JOIN users u ON u.id=b.created_by WHERE b.id=:id AND b.type=:type', ['id'=>$id, 'type'=>$type]);
        if (!$batch) throw new HttpException(404, 'Увозот не е пронајден.');
        return $batch;
    }

    private function categoryNames(): array
    {
        $names = [];
        foreach ($this->db->all('SELECT code,name FROM revenue_categories') as $row) $names[(string) $row['code']] = (string) $row['name'];
        return $names;
    }

    private function categoryOptions(?string $selected): string
    {
        $html = '';
        foreach ($this->db->all('SELECT id,code,name,revenue_group FROM revenue_categories WHERE active=1 ORDER BY sort_order,name') as $c) $html .= '<option value="'.(int) $c['id'].'"'.($selected === $c['code'] ? ' selected' : '').'>'.View::e(($c['revenue_group'] ? $c['revenue_group'].' · ' : '').$c['name']).'</option>';
        return $html;
    }

    private function kpi(string $label, string $value, string $noteHtml): string
    {
        return '<div class="kpi"><span class="kpi__label">'.View::e($label).'</span><strong class="kpi__value">'.View::e($value).'</strong>'.($noteHtml !== '' ? '<span class="kpi__note">'.(str_contains($noteHtml, '<a ') ? $noteHtml : View::e($noteHtml)).'</span>' : '').'</div>';
    }

    private function icon(string $name): string
    {
        return $name === 'upload' ? '<svg class="icon icon--sm" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>' : '';
    }

    private function displayDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date ? $date->format('d.m.Y') : $value;
    }
}
