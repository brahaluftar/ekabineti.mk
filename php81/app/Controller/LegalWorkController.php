<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\Pagination;
use EKabineti\Core\Request;
use EKabineti\Core\View;
use EKabineti\Service\EvidenceUi;
use EKabineti\Service\LegacySpreadsheetException;
use EKabineti\Service\LegalWorkImport;
use EKabineti\Service\LegalWorkReport;

/** Legal Office work reports (Word): import with preview, register grouped by section, domain, theme, officer and month. */
final class LegalWorkController extends Controller
{
    private const LINKS = ['linked'=>'Поврзано со постоечки предмет', 'created'=>'Предмет создаден од извештајот', 'none'=>'Без број на предмет'];

    private function view(): View { return new View($this->auth, $this->scope); }
    private function service(): LegalWorkImport { return new LegalWorkImport($this->db, $this->audit, $this->userId()); }
    private function canImport(): bool { return $this->scope->canWorkLegalOffice($this->userId()); }
    private function requireImport(): void { if (!$this->canImport()) throw new HttpException(403, 'Само активните корисници на Правната служба можат да увезуваат работни извештаи.'); }

    /** GET /legal-office/work-reports */
    public function index(): never
    {
        [$where, $params, $filters] = $this->filters();
        $from = 'FROM legal_work_activities a JOIN legal_work_reports r ON r.id=a.report_id WHERE '.$where;
        $totals = $this->db->one('SELECT COUNT(*) activities,COUNT(DISTINCT a.report_id) reports,COUNT(DISTINCT a.case_reference) cases,SUM(CASE WHEN a.hearing_date IS NOT NULL THEN 1 ELSE 0 END) hearings,MIN(r.period_start) date_from,MAX(r.period_end) date_to '.$from, $params) ?? [];
        $total = max(1, (int) ($totals['activities'] ?? 0));
        $body = '<div class="page-actions">'
            .($this->canImport() ? '<a class="button" href="/legal-office/work-reports/import" data-dialog-open="legal-work-import">Увези работен извештај</a>' : '')
            .'<a class="button button-secondary" href="/legal-office/work-reports/template.xlsx">Преземи Excel образец</a>'
            .'<a class="button button-secondary" href="/legal-office">Предмети на Правната служба</a>'
            .'<a class="button button-secondary" href="/legal-office/work-reports.xlsx?'.View::e(http_build_query($filters)).'">Извези во Excel</a></div>';
        $body .= $this->filterForm($filters);
        $body .= '<section class="kpi-grid" aria-label="Показатели">'
            .$this->kpi('Активности', (string) (int) ($totals['activities'] ?? 0), !empty($totals['date_from']) ? 'Од '.$this->displayDate((string) $totals['date_from']).' до '.$this->displayDate((string) $totals['date_to']) : 'Нема податоци за овој филтер')
            .$this->kpi('Извештаи', (string) (int) ($totals['reports'] ?? 0), '')
            .$this->kpi('Различни предмети', (string) (int) ($totals['cases'] ?? 0), 'според бројот на предметот')
            .$this->kpi('Рочиште', (string) (int) ($totals['hearings'] ?? 0), 'застапувања со датум')
            .'</section>';
        $group = function (string $title, string $column, string $filter, array $labels = []) use ($from, $params, $filters, $total): string {
            $rows = '';
            foreach ($this->db->all("SELECT {$column} k,COUNT(*) n ".$from." GROUP BY {$column} ORDER BY COUNT(*) DESC", $params) as $row) {
                $key = (string) ($row['k'] ?? '');
                $share = round((int) $row['n'] / $total * 100, 1);
                $name = $labels[$key] ?? ($key === '' ? '—' : $key);
                $rows .= '<tr><th scope="row">'.($key === '' ? View::e($name) : '<a href="?'.View::e(http_build_query([$filter=>$key] + $filters)).'#activities">'.View::e($name).'</a>').'</th><td class="num right">'.(int) $row['n'].'</td><td class="progress-cell"><progress class="progress-bar" max="100" value="'.$share.'" aria-hidden="true"></progress><span>'.$share.'%</span></td></tr>';
            }
            return '<section class="card"><h2>'.View::e($title).'</h2><div class="table-wrap"><table class="data-table"><thead><tr><th></th><th class="num right">Активности</th><th>Дел</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="3">—</td></tr>').'</tbody></table></div></section>';
        };
        $sections = [];
        foreach (LegalWorkReport::SECTIONS as [$code, $label]) $sections[$code] = $label;
        $body .= '<div class="grid">'.$group('Според видот на работа', 'a.section_code', 'section', $sections).$group('Според областа', 'a.domain', 'domain', LegalWorkReport::DOMAINS).$this->themeTable($from, $params, $filters, $total).'</div>';
        $body .= '<div class="grid">'.$group('Според службеникот', 'r.officer_name', 'officer').$group('Според месецот', "CONVERT(char(7),r.period_start,126)", 'month').$group('Врска со предметите', "COALESCE(a.case_link,'none')", 'link', self::LINKS).'</div>';

        $pager = Pagination::fromRequest();
        $rows = $pager->query($this->db, 'SELECT a.id,a.section_label,a.activity,a.parties,a.case_reference,a.subject,a.themes,a.domain,a.hearing_date,a.case_id,a.case_link,a.item_text,r.id report_id,r.officer_name,r.period_start,r.period_end,c.case_number '.str_replace('WHERE', 'LEFT JOIN administrative_cases c ON c.id=a.case_id WHERE', $from).' ORDER BY r.period_start DESC,r.id DESC,a.sort_order', $params);
        $body .= '<section class="card" id="activities"><h2>Активности</h2><div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Период</th><th>Службеник</th><th>Вид</th><th>Активност</th><th>Странки</th><th>Бр. на предмет</th><th>Предмет на спорот</th><th>Област</th><th>Рочиште</th></tr></thead><tbody>'.$this->activityRows($rows).'</tbody></table></div>'.$pager->nav().'</section>';

        $reportsPager = Pagination::fromRequest('reports');
        $reports = $reportsPager->query($this->db, 'SELECT r.id,r.officer_name,r.officer_title,r.report_date,r.period_start,r.period_end,r.activities,r.source_file_name,r.warnings FROM legal_work_reports r ORDER BY r.period_start DESC,r.id DESC');
        $list = '';
        foreach ($reports as $r) $list .= '<tr><td><a href="/legal-office/work-reports/'.(int) $r['id'].'">'.View::e($this->displayDate((string) $r['period_start']).' – '.$this->displayDate((string) $r['period_end'])).'</a></td><td>'.View::e($r['officer_name']).'<br><small class="muted">'.View::e($r['officer_title'] ?? '').'</small></td><td>'.View::e($r['report_date'] ? $this->displayDate((string) $r['report_date']) : '—').'</td><td class="num right">'.(int) $r['activities'].'</td><td>'.View::e($r['source_file_name'] ?? '').($r['warnings'] ? ' <span class="status-badge status-draft">белешки</span>' : '').'</td></tr>';
        $body .= '<section class="card"><h2>Увезени извештаи</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Период</th><th>Службеник</th><th>Датум на извештајот</th><th class="num right">Активности</th><th>Датотека</th></tr></thead><tbody>'.($list ?: '<tr><td colspan="5">Нема увезени извештаи.</td></tr>').'</tbody></table></div>'.$reportsPager->nav().'</section>';
        if ($this->canImport()) $body .= $this->importDialog();
        $this->view()->render('Работни извештаи на Правната служба', $body);
    }

    /** GET /legal-office/work-reports/{id}: one report, grouped by section as in the document, with the case links. */
    public function show(int $id): never
    {
        $report = $this->db->one('SELECT r.*,u.name created_by_name FROM legal_work_reports r LEFT JOIN users u ON u.id=r.created_by WHERE r.id=:id', ['id'=>$id]);
        if (!$report) throw new HttpException(404, 'Извештајот не е пронајден.');
        $activities = $this->db->all('SELECT a.*,c.case_number FROM legal_work_activities a LEFT JOIN administrative_cases c ON c.id=a.case_id WHERE a.report_id=:id ORDER BY a.sort_order', ['id'=>$id]);
        $sections = json_decode((string) $report['sections'], true) ?: [];
        $body = '<p><a class="back-link" href="'.View::e(View::listUrl('/legal-office/work-reports')).'">← Работни извештаи</a></p>'
            .'<section class="card"><h2>'.View::e($report['officer_name']).($report['officer_title'] ? ' · '.View::e($report['officer_title']) : '').'</h2>'
            .'<p>'.View::e($report['subject'] ?? '').'</p><p class="muted">Период '.View::e($this->displayDate((string) $report['period_start']).' – '.$this->displayDate((string) $report['period_end'])).' · датум на извештајот '.View::e($report['report_date'] ? $this->displayDate((string) $report['report_date']) : '—').' · увезено од '.View::e($report['created_by_name'] ?? '—').' · '.View::e($report['source_file_name'] ?? '').'</p>';
        foreach (json_decode((string) $report['warnings'], true) ?: [] as $warning) $body .= '<p class="alert">'.View::e($warning).'</p>';
        $body .= '</section>';
        foreach ($sections as $section) {
            $items = array_values(array_filter($activities, static fn(array $a): bool => $a['section_code'] === $section['code']));
            $body .= '<section class="card"><h2>'.View::e($section['label']).' <span class="muted">('.count($items).($section['declared_text'] !== '' ? ' · во извештајот: '.View::e($section['declared_text']) : '').')</span></h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Активност</th><th>Странки</th><th>Бр. на предмет</th><th>Предмет на спорот</th><th>Област</th><th>Рочиште</th></tr></thead><tbody>';
            foreach ($items as $a) $body .= '<tr><td>'.View::e($a['activity']).'</td><td>'.View::e($a['parties'] ?? '').'</td><td>'.$this->caseCell($a).'</td><td>'.View::e($a['themes'] ?? $a['subject'] ?? '').'</td><td>'.View::e(LegalWorkReport::DOMAINS[$a['domain']] ?? $a['domain']).'</td><td>'.View::e($a['hearing_date'] ? $this->displayDate((string) $a['hearing_date']) : '').'</td></tr>';
            $body .= '</tbody></table></div></section>';
        }
        $this->view()->render('Работен извештај · '.$this->displayDate((string) $report['period_start']).' – '.$this->displayDate((string) $report['period_end']), $body);
    }

    public function importForm(): never
    {
        $this->requireImport();
        $this->view()->render('Увези работен извештај', '<p><a class="button button-secondary" href="/legal-office/work-reports">Назад</a></p><section class="card">'.$this->importFields(false).'</section>');
    }

    /** POST /legal-office/work-reports/import: stores the Word file privately and prepares the preview. */
    public function importPreview(): never
    {
        $this->requireImport();
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpException(422, 'Изберете го работниот извештај (Word).');
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['doc', 'docx', 'xlsx'], true)) throw new HttpException(422, 'Дозволени се извештаи во Word (.docx, .doc) или Excel образецот (.xlsx).');
        if ((int) $file['size'] > 20 * 1048576) throw new HttpException(422, 'Документот надминува 20 MB.');
        $id = Request::uuid();
        $directory = APP_ROOT.'/storage/private/imports/'.$id;
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Приватниот директориум не е создаден.');
        $path = $directory.'/source.'.$extension;
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) throw new \RuntimeException('Прикачувањето не успеа.');
        $this->db->execute("INSERT INTO import_batches(id,type,storage_key,status,created_by,created_at,updated_at) VALUES(:id,:type,:key,'preview',:user,SYSUTCDATETIME(),SYSUTCDATETIME())", ['id'=>$id, 'type'=>LegalWorkImport::TYPE, 'key'=>'imports/'.$id.'/source.'.$extension, 'user'=>$this->userId()]);
        try {
            $reports = $extension === 'xlsx' ? LegalWorkReport::fromSheet($this->templateSheet($path)) : LegalWorkReport::parse(LegalWorkReport::paragraphs($path));
            $responsible = $this->request->integer('responsible_user_id') ?: null;
            if ($responsible !== null && !isset($this->service()->legalStaff()[$responsible]) && $responsible !== $this->service()->defaultResponsible()) throw new HttpException(422, 'Избраното одговорно лице не е активен службеник на Правната служба.');
            $plan = $this->service()->preview($id, $reports, mb_substr(basename((string) $file['name']), 0, 255), $responsible);
        } catch (LegacySpreadsheetException|\RuntimeException $exception) {
            $this->db->execute("UPDATE import_batches SET status='invalid',errors=:errors,updated_at=SYSUTCDATETIME() WHERE id=:id", ['errors'=>json_encode([['message'=>$exception->getMessage()]], JSON_UNESCAPED_UNICODE), 'id'=>$id]);
            throw new HttpException(422, $exception->getMessage());
        }
        $c = $plan['counts'];
        $this->redirect('/legal-office/work-reports/imports/'.$id, $plan['status'] === 'ready' ? $c['activities'].' активности во '.$c['reports'].' извештај(и): '.$c['linked'].' се поврзуваат со постоечки предмети, '.$c['new_cases'].' нови предмети, '.$c['hearings'].' seanca.' : ($plan['warnings'][0] ?? 'Во документот не е пронајден ниеден работен извештај (Датум/и, Од, Тема и делови со листи).'));
    }

    /** GET /legal-office/work-reports/imports/{id}: what will be (or was) stored, created and linked. */
    public function importShow(string $id): never
    {
        $batch = $this->db->one('SELECT b.*,u.name user_name FROM import_batches b LEFT JOIN users u ON u.id=b.created_by WHERE b.id=:id AND b.type=:type', ['id'=>$id, 'type'=>LegalWorkImport::TYPE]);
        if (!$batch) throw new HttpException(404, 'Увозот не е пронајден.');
        $plan = json_decode((string) $batch['summary'], true) ?: [];
        $status = (string) $batch['status'];
        $c = (array) ($plan['counts'] ?? []);
        $body = '<p><a class="button button-secondary" href="/legal-office/work-reports">Назад кон работните извештаи</a></p><section class="card"><h2>'.View::e($plan['source_file_name'] ?? 'Извештај').'</h2>'
            .'<p class="muted">Прикачено од '.View::e($batch['user_name'] ?? '—').' во '.View::e(substr((string) $batch['created_at'], 0, 16)).'</p>';
        if ($status === 'invalid') $body .= '<div class="alert alert--error" role="alert">'.View::e(((json_decode((string) $batch['errors'], true) ?: [])[0]['message'] ?? ($plan['warnings'][0] ?? 'Во документот не е пронајден ниеден работен извештај.'))).'</div>';
        if (isset($plan['posted'])) {
            $p = $plan['posted'];
            $body .= '<div class="alert alert--success" role="status">Увезени се '.(int) $p['reports'].' извештај(и) со '.(int) $p['activities'].' активности · '.(int) $p['cases_created'].' создадени предмети · '.(int) $p['cases_linked'].' врски со постоечки предмети · '.(int) $p['hearings_created'].' нови рочишта'.((int) $p['hearings_reused'] ? ', '.(int) $p['hearings_reused'].' ekzistonin' : '').((int) $p['replaced'] ? ' · '.(int) $p['replaced'].' претходни извештај(и) се заменети' : '').'.</div>';
        }
        $body .= '<div class="kpi-grid">'.$this->kpi('Активности', (string) (int) ($c['activities'] ?? 0), (int) ($c['reports'] ?? 0).' извештај(и)').$this->kpi('Се поврзуваат со постоечки предмети', (string) (int) ($c['linked'] ?? 0), 'според бројот на предметот').$this->kpi('Нови предмети', (string) (int) ($c['new_cases'] ?? 0), 'се создаваат во Правната служба').$this->kpi('Рочиште', (string) (int) ($c['hearings'] ?? 0), (int) ($c['without_case'] ?? 0).' активности без број на предмет').'</div>';
        if (!empty($plan['responsible_user_id']) && (int) ($c['new_cases'] ?? 0) > 0) $body .= '<p class="muted">Новите предмети се доделуваат на: <strong>'.View::e((string) $this->db->scalar('SELECT CONCAT(name,\' · @\',username) FROM users WHERE id=:id', ['id'=>(int) $plan['responsible_user_id']])).'</strong>.</p>';
        if ((int) ($c['replaced'] ?? 0) > 0 && $status === 'ready') $body .= '<p class="alert">'.(int) $c['replaced'].' од извештаите се веќе увезени (ист службеник и период). Со потврда, нивните активности се заменуваат; предметите и рочиштата не се дуплираат.</p>';
        if ($status === 'ready' && $this->canImport()) {
            $body .= '<div class="form-actions"><form method="post" action="/legal-office/work-reports/imports/'.View::e($id).'/confirm">'.$this->view()->csrf().'<button class="primary">Потврди го увозот</button></form><form method="post" action="/legal-office/work-reports/imports/'.View::e($id).'/cancel">'.$this->view()->csrf().'<button class="button button-secondary">Откажи</button></form></div>';
        }
        $body .= '</section>';
        foreach ((array) ($plan['reports'] ?? []) as $report) {
            $body .= '<section class="card"><h2>'.View::e(($report['officer_name'] ?? '—').' · '.$this->displayDate((string) $report['period_start']).' – '.$this->displayDate((string) $report['period_end'])).'</h2><p class="muted">'.View::e($report['subject'] ?? '').'</p>';
            foreach ($report['warnings'] ?? [] as $warning) $body .= '<p class="alert">'.View::e($warning).'</p>';
            $body .= '<div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Вид</th><th>Активност</th><th>Странки</th><th>Бр. на предмет</th><th>Предмет на спорот</th><th>Област</th><th>Рочиште</th><th>Предмет</th></tr></thead><tbody>';
            foreach ($report['items'] as $item) {
                $link = match ($item['plan'] ?? 'none') { 'linked'=>'<span class="status-badge status-active">Поврзано со '.View::e($item['case_number'] ?? '').'</span>', 'created'=>'<span class="status-badge status-draft">Нов предмет</span>', default=>'<span class="muted">—</span>' };
                $body .= '<tr><td>'.View::e($item['section_label']).'</td><td>'.View::e($item['activity']).'</td><td>'.View::e($item['parties']).'</td><td><code>'.View::e($item['case_reference'] ?? '').'</code></td><td>'.View::e(implode(', ', $item['themes']) ?: $item['subject']).'</td><td>'.View::e(LegalWorkReport::DOMAINS[$item['domain']] ?? $item['domain']).'</td><td>'.View::e($item['hearing_date'] ? $this->displayDate((string) $item['hearing_date']) : '').'</td><td>'.$link.'</td></tr>';
            }
            $body .= '</tbody></table></div></section>';
        }
        if (!empty($plan['new_cases']) && !isset($plan['posted'])) {
            $rows = '';
            foreach ($plan['new_cases'] as $new) $rows .= '<tr><td><code>'.View::e($new['court_reference']).'</code></td><td>'.View::e(implode(', ', $new['parties'])).'</td><td>'.View::e(implode(', ', $new['themes'])).'</td><td>'.View::e(LegalWorkImport::authority((string) $new['court_reference'])).'</td></tr>';
            $body .= '<section class="card"><h2>Предмети што се создаваат во Правната служба</h2><p class="muted">Со тужителите како странка и Општина Битола како тужена страна. Судот се определува според бројот на предметот и може да се промени во предметот.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Бр. на предмет</th><th>Тужители</th><th>Предмет на спорот</th><th>Суд</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        }
        $this->view()->render('Увоз на работниот извештај', $body);
    }

    public function importConfirm(string $id): never
    {
        $this->requireImport();
        $r = $this->service()->confirm($id);
        $this->redirect('/legal-office/work-reports/imports/'.$id, 'Увезени се '.$r['activities'].' активности; '.$r['cases_created'].' нови предмети и '.$r['hearings_created'].' seanca.');
    }

    public function importCancel(string $id): never
    {
        $this->requireImport();
        $this->service()->cancel($id);
        $this->redirect('/legal-office/work-reports', 'Увозот е откажан.');
    }

    /**
     * GET /legal-office/work-reports/template.xlsx: sheet "Aktivitetet" (empty, with drop-down lists for the type of work
     * and the subject), an example sheet, the instructions and the lists. Importable again through the same dialog.
     */
    public function template(): never
    {
        $headers = LegalWorkReport::TEMPLATE_COLUMNS;
        $sections = array_values(array_unique(LegalWorkReport::sectionLabels()));
        $themes = array_values(array_unique(LegalWorkReport::themeLabels())); sort($themes);
        $lists = [];
        foreach (range(0, max(count($sections), count($themes), count(LegalWorkReport::DOMAINS)) - 1) as $i) $lists[] = ['section'=>$sections[$i] ?? '', 'theme'=>$themes[$i] ?? '', 'domain'=>array_values(LegalWorkReport::DOMAINS)[$i] ?? ''];
        $example = [
            ['report_date'=>'09.10.2026', 'officer_name'=>'Име Презиме', 'officer_title'=>'Правен службеник', 'period_start'=>'05.10.2026', 'period_end'=>'09.10.2026', 'section'=>'Одговор на тужба', 'activity'=>'Одговор на тужба', 'parties'=>'Тужител Пример и др.', 'case_reference'=>'C.nr.1234/26', 'subject'=>'дневници, инфлација', 'hearing_date'=>''],
            ['report_date'=>'09.10.2026', 'officer_name'=>'Име Презиме', 'officer_title'=>'Правен службеник', 'period_start'=>'05.10.2026', 'period_end'=>'09.10.2026', 'section'=>'Застапувања на рочишта', 'activity'=>'Застапување на судско рочиште', 'parties'=>'Друг тужител', 'case_reference'=>'C.nr.5678/25', 'subject'=>'потврда за сопственост', 'hearing_date'=>'14.10.2026'],
            ['report_date'=>'09.10.2026', 'officer_name'=>'Име Презиме', 'officer_title'=>'Правен службеник', 'period_start'=>'05.10.2026', 'period_end'=>'09.10.2026', 'section'=>'Канцелариска работа', 'activity'=>'Барање за докази до Општинскиот архив', 'parties'=>'Друг тужител', 'case_reference'=>'C.nr.5678/25', 'subject'=>'', 'hearing_date'=>''],
        ];
        $instructions = [
            ['step'=>'1', 'text'=>'Пополнете го листот „Активности“: еден ред за секоја активност. Не ги менувајте насловите на колоните.'],
            ['step'=>'2', 'text'=>'Датум на извештајот, Период од, Период до и Датум на рочиштето: dd.mm.yyyy (на пр. 09.10.2026).'],
            ['step'=>'3', 'text'=>'Службеникот и периодот ги спојуваат редовите во еден извештај. Истиот службеник и период, повторно увезени, го заменуваат претходниот извештај.'],
            ['step'=>'4', 'text'=>'Вид на работа: изберете од листата (Одговор на тужба, Поднесок, Застапувања на рочишта, Жалба, Ревизија, Канцелариска работа …).'],
            ['step'=>'5', 'text'=>'Странки: одделете со точка-запирка (;). „и др.“ е дозволено.'],
            ['step'=>'6', 'text'=>'Бр. на предмет: како во судот (П-2257/26, ПЛ1-844/26, ГЖ-628/26, ПОУ-927/26). Предметот се поврзува со Правната служба или се создава.'],
            ['step'=>'7', 'text'=>'Предмет на спорот: слободен текст; познатите зборови (дневници, инфлација, квалификација, дежурство, викенд, годишен одмор, потврда за сопственост, надомест на штета, поништување на одлука …) се групираат сами. Видете го листот „Листи“.'],
            ['step'=>'8', 'text'=>'Датум на рочиштето: само за застапувањата; создава или го користи рочиштето на предметот на тој ден.'],
            ['step'=>'9', 'text'=>'Листот „Пример“ покажува како се пополнува и не се увезува. Увоз: Правна служба → Работни извештаи → Увези работен извештај.'],
        ];
        $this->audit->record($this->userId(), 'legal_work_report.template_downloaded', 'LegalWorkReport', null, [], []);
        $widths = ['report_date'=>14, 'officer_name'=>22, 'officer_title'=>18, 'period_start'=>13, 'period_end'=>13, 'section'=>28, 'activity'=>34, 'parties'=>30, 'case_reference'=>18, 'subject'=>30, 'hearing_date'=>15];
        (new \EKabineti\Service\TabularExport())->xlsx('Obrazec_raboten_izveshtaj_Pravna_sluzhba', [
            ['name'=>LegalWorkReport::TEMPLATE_SHEET, 'headers'=>$headers, 'rows'=>[], 'widths'=>$widths, 'lists'=>['section'=>'Listat!$A$2:$A$'.(count($sections) + 1)], 'filter'=>false],
            ['name'=>'Пример', 'headers'=>$headers, 'rows'=>$example, 'widths'=>$widths, 'filter'=>false],
            ['name'=>'Упатства', 'headers'=>['step'=>'Чекор', 'text'=>'Упатство'], 'rows'=>$instructions, 'widths'=>['step'=>8, 'text'=>110], 'filter'=>false],
            ['name'=>'Листи', 'headers'=>['section'=>'Вид на работа', 'theme'=>'Предмет на спорот (познати зборови)', 'domain'=>'Област'], 'rows'=>$lists, 'widths'=>['section'=>32, 'theme'=>30, 'domain'=>22], 'filter'=>false],
        ]);
    }

    /** First sheet of the workbook that has the template headers (normally "Aktivitetet"). */
    private function templateSheet(string $path): array
    {
        $sheets = (new \EKabineti\Service\SpreadsheetReader())->sheets($path);
        if (isset($sheets[LegalWorkReport::TEMPLATE_SHEET])) return $sheets[LegalWorkReport::TEMPLATE_SHEET];
        foreach ($sheets as $name => $rows) if ($name !== 'Пример' && $name !== 'Упатства' && $name !== 'Листи') return $rows;
        return [];
    }

    /** GET /legal-office/work-reports.xlsx with the filters of the page. */
    public function export(): never
    {
        [$where, $params] = $this->filters();
        $rows = $this->db->all('SELECT CONVERT(varchar(10),r.period_start,23) period_start,CONVERT(varchar(10),r.period_end,23) period_end,r.officer_name,a.section_label,a.activity,a.parties,a.case_reference,c.case_number,a.subject,a.themes,a.domain,CONVERT(varchar(10),a.hearing_date,23) hearing_date,a.item_text FROM legal_work_activities a JOIN legal_work_reports r ON r.id=a.report_id LEFT JOIN administrative_cases c ON c.id=a.case_id WHERE '.$where.' ORDER BY r.period_start,r.id,a.sort_order', $params);
        foreach ($rows as &$row) $row['domain'] = LegalWorkReport::DOMAINS[$row['domain']] ?? $row['domain'];
        unset($row);
        $headers = ['period_start'=>'Од', 'period_end'=>'До', 'officer_name'=>'Службеник', 'section_label'=>'Вид на работа', 'activity'=>'Активност', 'parties'=>'Странки', 'case_reference'=>'Бр. на предмет', 'case_number'=>'Предмет во е-Кабинет', 'subject'=>'Предмет на спорот (како во извештајот)', 'themes'=>'Предмет на спорот (групиран)', 'domain'=>'Област', 'hearing_date'=>'Рочиште', 'item_text'=>'Ред во извештајот'];
        $this->audit->record($this->userId(), 'legal_work_report.exported', 'LegalWorkReport', null, [], ['rows'=>count($rows)]);
        (new \EKabineti\Service\TabularExport())->xlsx('e-kabineti-raportet-e-punes-'.date('Ymd-His'), [['name'=>'Активности', 'headers'=>$headers, 'rows'=>$rows]]);
    }

    private function filters(): array
    {
        $where = ['1=1']; $params = []; $filters = [];
        foreach (['from'=>'r.period_end>=:from', 'to'=>'r.period_start<=:to'] as $name => $condition) {
            $value = trim((string) ($_GET[$name] ?? ''));
            $date = $value !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date && $date->format('Y-m-d') === $value) { $where[] = $condition; $params[$name] = $value; $filters[$name] = $value; }
        }
        $codes = array_column(LegalWorkReport::SECTIONS, 0);
        $section = (string) ($_GET['section'] ?? '');
        if (in_array($section, $codes, true) || preg_match('/^other_[0-9a-f]{8}$/', $section)) { $where[] = 'a.section_code=:section'; $params['section'] = $section; $filters['section'] = $section; }
        $domain = (string) ($_GET['domain'] ?? '');
        if (isset(LegalWorkReport::DOMAINS[$domain])) { $where[] = 'a.domain=:domain'; $params['domain'] = $domain; $filters['domain'] = $domain; }
        $theme = (string) ($_GET['theme'] ?? '');
        if (in_array($theme, array_column(LegalWorkReport::THEMES, 0), true)) { $where[] = 'a.theme_codes LIKE :theme'; $params['theme'] = '%,'.$theme.',%'; $filters['theme'] = $theme; }
        $officer = trim((string) ($_GET['officer'] ?? ''));
        if ($officer !== '' && mb_strlen($officer) <= 200) { $where[] = 'r.officer_name=:officer'; $params['officer'] = $officer; $filters['officer'] = $officer; }
        $month = (string) ($_GET['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) { $where[] = 'r.period_start>=:m_from AND r.period_start<:m_to'; $params['m_from'] = $month.'-01'; $params['m_to'] = date('Y-m-d', strtotime($month.'-01 +1 month')); $filters['month'] = $month; }
        $link = (string) ($_GET['link'] ?? '');
        if (isset(self::LINKS[$link])) { $where[] = $link === 'none' ? 'a.case_link IS NULL' : 'a.case_link=:link'; if ($link !== 'none') $params['link'] = $link; $filters['link'] = $link; }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '' && mb_strlen($q) <= 100) { $where[] = '(a.item_text LIKE :q1 OR a.parties LIKE :q2 OR a.case_reference LIKE :q3)'; foreach ([1, 2, 3] as $i) $params['q'.$i] = '%'.$q.'%'; $filters['q'] = $q; }
        return [implode(' AND ', $where), $params, $filters];
    }

    private function filterForm(array $filters): string
    {
        $option = static function (array $options, string $selected, string $all): string {
            $html = '<option value="">'.View::e($all).'</option>';
            foreach ($options as $value => $label) $html .= '<option value="'.View::e($value).'"'.($selected === (string) $value ? ' selected' : '').'>'.View::e($label).'</option>';
            return $html;
        };
        $sections = []; foreach (LegalWorkReport::SECTIONS as [$code, $label]) $sections[$code] = $label;
        $themes = []; foreach (LegalWorkReport::THEMES as [$code, $label]) $themes[$code] = $label; asort($themes);
        $officers = []; foreach ($this->db->all('SELECT DISTINCT officer_name FROM legal_work_reports ORDER BY officer_name') as $o) $officers[(string) $o['officer_name']] = (string) $o['officer_name'];
        $chips = '';
        foreach ($filters as $name => $value) $chips .= '<a class="chip is-active" href="?'.View::e(http_build_query(array_diff_key($filters, [$name=>1]))).'">'.View::e($this->filterLabel($name, (string) $value)).' <span aria-hidden="true">×</span></a>';
        return '<form method="get" class="card filter-card revenue-filters"><div class="filter-bar">'
            .'<label>Пребарај<input name="q" value="'.View::e($filters['q'] ?? '').'" placeholder="Странка, бр. на предмет, текст…"></label>'
            .'<label>Од<input type="date" name="from" value="'.View::e($filters['from'] ?? '').'"></label><label>До<input type="date" name="to" value="'.View::e($filters['to'] ?? '').'"></label>'
            .'<label>Службеник<select name="officer">'.$option($officers, $filters['officer'] ?? '', 'Сите').'</select></label>'
            .'<label>Вид на работа<select name="section">'.$option(array_unique($sections), $filters['section'] ?? '', 'Сите').'</select></label>'
            .'<label>Област<select name="domain">'.$option(LegalWorkReport::DOMAINS, $filters['domain'] ?? '', 'Сите').'</select></label>'
            .'<label>Предмет на спорот<select name="theme">'.$option(array_unique($themes), $filters['theme'] ?? '', 'Сите').'</select></label>'
            .'<label>Врска<select name="link">'.$option(self::LINKS, $filters['link'] ?? '', 'Сите').'</select></label>'
            .(isset($filters['month']) ? '<input type="hidden" name="month" value="'.View::e($filters['month']).'">' : '')
            .'</div><div class="form-actions"><button class="primary">Филтрирај</button><a class="button button-secondary" href="/legal-office/work-reports">Исчисти</a></div></form>'
            .($chips !== '' ? '<div class="chips active-filters">'.$chips.'</div>' : '');
    }

    private function filterLabel(string $name, string $value): string
    {
        $sections = []; foreach (LegalWorkReport::SECTIONS as [$code, $label]) $sections[$code] = $label;
        $themes = []; foreach (LegalWorkReport::THEMES as [$code, $label]) $themes[$code] = $label;
        return match ($name) {
            'section' => 'Вид: '.($sections[$value] ?? $value), 'domain' => 'Област: '.(LegalWorkReport::DOMAINS[$value] ?? $value), 'theme' => 'Предмет на спорот: '.($themes[$value] ?? $value),
            'officer' => 'Службеник: '.$value, 'month' => 'Месец: '.$value, 'link' => self::LINKS[$value] ?? $value, 'from' => 'Од: '.$this->displayDate($value), 'to' => 'До: '.$this->displayDate($value),
            default => 'Пребарување: '.$value,
        };
    }

    /** Themes are stored as ",meals,inflation," so one activity counts in each of its themes. */
    private function themeTable(string $from, array $params, array $filters, int $total): string
    {
        $counts = [];
        foreach ($this->db->all('SELECT a.theme_codes '.$from.' AND a.theme_codes IS NOT NULL', $params) as $row) foreach (array_filter(explode(',', (string) $row['theme_codes'])) as $code) $counts[$code] = ($counts[$code] ?? 0) + 1;
        arsort($counts);
        $labels = []; foreach (LegalWorkReport::THEMES as [$code, $label]) $labels[$code] = $label;
        $rows = '';
        foreach ($counts as $code => $n) {
            $share = round($n / $total * 100, 1);
            $rows .= '<tr><th scope="row"><a href="?'.View::e(http_build_query(['theme'=>$code] + $filters)).'#activities">'.View::e($labels[$code] ?? $code).'</a></th><td class="num right">'.$n.'</td><td class="progress-cell"><progress class="progress-bar" max="100" value="'.$share.'" aria-hidden="true"></progress><span>'.$share.'%</span></td></tr>';
        }
        return '<section class="card"><h2>Според предметот на спорот</h2><div class="table-wrap"><table class="data-table"><thead><tr><th></th><th class="num right">Активности</th><th>Дел</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="3">—</td></tr>').'</tbody></table></div></section>';
    }

    private function activityRows(array $rows): string
    {
        $html = '';
        foreach ($rows as $a) $html .= '<tr><td><a href="/legal-office/work-reports/'.(int) $a['report_id'].'">'.View::e($this->displayDate((string) $a['period_start']).' – '.$this->displayDate((string) $a['period_end'])).'</a></td><td>'.View::e($a['officer_name']).'</td><td>'.View::e($a['section_label']).'</td><td title="'.View::e($a['item_text']).'">'.View::e($a['activity']).'</td><td>'.View::e($a['parties'] ?? '').'</td><td>'.$this->caseCell($a).'</td><td>'.View::e($a['themes'] ?? $a['subject'] ?? '').'</td><td>'.View::e(LegalWorkReport::DOMAINS[$a['domain']] ?? $a['domain']).'</td><td>'.View::e($a['hearing_date'] ? $this->displayDate((string) $a['hearing_date']) : '').'</td></tr>';
        return $html ?: '<tr><td colspan="9">Нема активности.'.($this->canImport() ? ' Увезете работен извештај.' : '').'</td></tr>';
    }

    private function caseCell(array $a): string
    {
        if (!$a['case_reference']) return '<span class="muted">—</span>';
        $code = '<code>'.View::e($a['case_reference']).'</code>';
        if (!$a['case_id']) return $code;
        return $code.'<br><a href="/legal-office/cases/'.(int) $a['case_id'].'">'.View::e($a['case_number'] ?? 'Предмет').'</a>'.($a['case_link'] === 'created' ? ' <small class="muted">(создаден од извештајот)</small>' : '');
    }

    private function importDialog(): string
    {
        return '<dialog class="modal" id="legal-work-import" aria-labelledby="legal-work-import-title" data-dialog>'
            .'<header class="modal__header"><div><p class="eyebrow">Правна служба</p><h2 id="legal-work-import-title">Увези работен извештај</h2></div><button type="button" class="icon-button" data-modal-close aria-label="Затвори"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>'
            .$this->importFields(true).'</dialog>';
    }

    private function importFields(bool $dialog): string
    {
        $fields = EvidenceUi::dropzone('file', false, true, 'Word (.docx, .doc) или Excel образецот (.xlsx)', 'Работен извештај', '.docx,.doc,.xlsx,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            .'<p class="wide muted">Немате Word извештај? Пополнете го <a href="/legal-office/work-reports/template.xlsx">Excel образецот</a>: еден ред за секоја активност; редовите со ист службеник и период стануваат еден извештај.</p>'
            .'<p class="wide muted">Се читаат датумот, службеникот, периодот и деловите (Одговор на тужба, Поднесок, Застапувања, Жалба, Ревизија, Канцелариска работа …) со секој ред од листата. Секој број на предмет се поврзува со предметот на Правната служба или се создава како нов предмет; застапувањата со датум се додаваат како рочишта. Пред евидентирањето погледнете го прегледот.</p>'
            .'<p class="wide muted">Истиот извештај (службеник и период) може повторно да се увезе: неговите активности се заменуваат, предметите и рочиштата не се дуплираат.</p>'
            .$this->responsibleField();
        if (!$dialog) return '<form method="post" action="/legal-office/work-reports/import" enctype="multipart/form-data" class="form-grid">'.$this->view()->csrf().$fields.'<button class="primary">Прочитај го извештајот</button></form>';
        return '<form method="post" action="/legal-office/work-reports/import" enctype="multipart/form-data" class="modal__form">'.$this->view()->csrf().'<div class="modal__body form-grid">'.$fields.'</div>'
            .'<footer class="modal__footer"><span class="modal__keys muted">Esc затвора</span><div class="inline-actions"><button type="button" class="button button-secondary" data-modal-close>Откажи</button><button class="primary">Прочитај го извештајот</button></div></footer></form>';
    }

    /** Who becomes responsible for the cases the import creates (default: the head of the Legal Office; or the officer of each report). */
    private function responsibleField(): string
    {
        $service = $this->service();
        $staff = $service->legalStaff();
        $default = $service->defaultResponsible();
        if ($default !== null && !isset($staff[$default])) $staff[$default] = (string) $this->db->scalar('SELECT CONCAT(name,\' · @\',username) FROM users WHERE id=:id', ['id'=>$default]);
        $options = '<option value="">Службеник на извештајот (кога постои корисник со тоа име)</option>';
        foreach ($staff as $id => $label) $options .= '<option value="'.(int) $id.'"'.($id === $default ? ' selected' : '').'>'.View::e($label).'</option>';
        return '<label class="wide">Одговорно лице за новите предмети<select name="responsible_user_id">'.$options.'</select><small class="field-hint">Постојните предмети не го менуваат одговорното лице.</small></label>';
    }

    private function kpi(string $label, string $value, string $note): string
    {
        return '<div class="kpi"><span class="kpi__label">'.View::e($label).'</span><strong class="kpi__value">'.View::e($value).'</strong>'.($note !== '' ? '<span class="kpi__note">'.View::e($note).'</span>' : '').'</div>';
    }

    private function displayDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date ? $date->format('d.m.Y') : $value;
    }
}
