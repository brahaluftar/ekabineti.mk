<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Service\CitizenServiceCatalog;

/**
 * Weekly report per service (/citizen-services/by-service), filled by the sector heads / officers, the drill-down
 * report (directorate → sector → category → service) and the service catalog with tiers and reporters.
 */
final class CitizenServiceCatalogController extends Controller
{
    private const LEVELS = ['directorate'=>'Сектор', 'sector'=>'Одделение', 'category'=>'Категорија', 'service'=>'Услуга'];

    private function view(): View { return new View($this->auth, $this->scope); }
    private function catalog(): CitizenServiceCatalog { return new CitizenServiceCatalog($this->db); }
    private function requireAccess(): void { if (!$this->scope->canAccessCitizenServices($this->userId())) throw new HttpException(403, 'Немате дозвола за известување за услугите за граѓаните.'); }

    /** GET /citizen-services/by-service?week_start= : the sectors the user reports, with their form. */
    public function reporting(): never
    {
        $this->requireAccess();
        $week = $this->week();
        $catalog = $this->catalog();
        $sectors = array_values(array_filter($catalog->sectors($this->scope->citizenReportDirectorateIds($this->userId())), fn(array $s): bool => (int) $s['services'] > 0 && $this->scope->canReportCitizenSector($this->userId(), (int) $s['id'], (int) $s['directorate_id'])));
        $body = $this->nav($week, 'reporting');
        $body .= '<section class="card"><h2>Неделно известување по услуги</h2><p class="muted">Раководителите на одделенијата и надлежните службеници ги внесуваат бројките за неделата за секоја услуга на одделението. Услугите со мал обем (ниво C) се внесуваат заедно по категорија. Празните полиња се сметаат како 0. Збировите на шалтерите на секторот се пресметуваат автоматски од овие бројки.</p></section>';
        if ($sectors === [] && $this->scope->canViewCitizenServicesReportOnly($this->userId())) {
            $body .= '<section class="card"><p>Оваа улога го гледа извештајот без да го пополнува. Отворете го <a href="/citizen-services/by-service/report?week_start='.$week.'">Извештајот по услуги</a>.</p></section>';
            $this->view()->render('Известување по услуги', $body);
        }
        if ($sectors === []) {
            $body .= '<section class="card"><p>Не сте определени за известување за ниедно одделение. Раководителот на секторот ве определува во <a href="/citizen-services/catalog">Каталог на услуги</a>.</p></section>';
            $this->view()->render('Известување по услуги', $body);
        }
        foreach ($sectors as $sector) $body .= $this->sectorForm($catalog, $sector, $week);
        $this->view()->render('Известување по услуги', $body);
    }

    /** POST /citizen-services/sectors/{id}/weekly */
    public function save(int $sectorId): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $sector = $catalog->sector($sectorId);
        if (!$sector) throw new HttpException(404, 'Одделението не е пронајдено.');
        if (!$this->scope->canReportCitizenSector($this->userId(), $sectorId, (int) $sector['directorate_id'])) throw new HttpException(403, 'Немате дозвола за известување за ова одделение.');
        $week = (string) $this->date('week_start');
        $before = $catalog->values($sectorId, $week);
        try { $result = $catalog->save($sectorId, $week, is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [], $this->userId()); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $this->audit->record($this->userId(), $before ? 'citizen_service_sector_report.updated' : 'citizen_service_sector_report.created', 'CitizenServiceSector', $sectorId, $before ? ['rows'=>array_map(static fn(array $r): array => array_intersect_key($r, array_flip(CitizenServiceCatalog::FIELDS)), $before)] : [], ['week_start'=>$week, 'rows'=>$result['rows'], 'windows'=>$result['windows']]);
        $this->redirect('/citizen-services/by-service?week_start='.$week.'#sector-'.$sectorId, 'Извештајот на одделението е зачуван ('.$result['rows'].' редови).');
    }

    /** GET /citizen-services/by-service/report?week_start=&level=&directorate_id=&sector_id=&category= */
    public function report(): never
    {
        $this->requireAccess();
        $week = $this->week();
        $catalog = $this->catalog();
        $scope = $this->scope->citizenReportDirectorateIds($this->userId());
        $filters = ['directorate_id'=>(int) ($_GET['directorate_id'] ?? 0) ?: null, 'sector_id'=>(int) ($_GET['sector_id'] ?? 0) ?: null, 'category'=>isset(CitizenServiceCatalog::CATEGORIES[(string) ($_GET['category'] ?? '')]) ? (string) $_GET['category'] : null];
        $level = (string) ($_GET['level'] ?? '');
        if (!isset(self::LEVELS[$level])) $level = $filters['sector_id'] ? ($filters['category'] ? 'service' : 'category') : ($filters['directorate_id'] ? 'sector' : 'directorate');
        $rows = $catalog->summary($week, $level, $filters, $scope);

        // Trail: Të gjitha › directorate › sector › category.
        $trail = ['<a href="/citizen-services/by-service/report?week_start='.$week.'">Сите сектори</a>'];
        if ($filters['directorate_id']) { $d = $this->db->one('SELECT code,name FROM directorates WHERE id=:id', ['id'=>$filters['directorate_id']]); $trail[] = '<a href="?'.View::e(http_build_query(['week_start'=>$week, 'directorate_id'=>$filters['directorate_id']])).'">'.View::e(($d['code'] ?? '').' · '.($d['name'] ?? '')).'</a>'; }
        if ($filters['sector_id']) { $s = $catalog->sector((int) $filters['sector_id']); $trail[] = '<a href="?'.View::e(http_build_query(array_filter(['week_start'=>$week, 'directorate_id'=>$filters['directorate_id'], 'sector_id'=>$filters['sector_id']]))).'">'.View::e($s['name'] ?? '').'</a>'; }
        if ($filters['category']) $trail[] = View::e(CitizenServiceCatalog::categoryLabel($filters['category']));

        $totals = array_fill_keys(['received', 'resolved', 'rejected', 'open_count', 'over_deadline', 'online_count', 'online_base', 'amount', 'previous_received'], 0);
        $html = '';
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) $totals[$k] += (float) $r[$k];
            $next = match ($level) {
                'directorate'=>['directorate_id'=>$r['k']],
                'sector'=>['sector_id'=>$r['k']] + array_filter(['directorate_id'=>$filters['directorate_id']]),
                'category'=>['category'=>$r['k']] + array_filter(['directorate_id'=>$filters['directorate_id'], 'sector_id'=>$filters['sector_id']]),
                default=>null,
            };
            $label = View::e(($level === 'directorate' ? $r['code'].' · ' : '').$r['name']);
            $html .= '<tr><th scope="row">'.($next ? '<a href="?'.View::e(http_build_query(['week_start'=>$week] + $next)).'">'.$label.'</a>' : $label).'</th>'.$this->cells($r).'</tr>';
        }
        $body = $this->nav($week, 'report');
        $body .= '<section class="card"><h2>Неделен извештај по услуги</h2><p class="muted">'.implode(' › ', $trail).'</p><p class="muted">Недела '.View::e($this->displayWeek($week)).' во споредба со претходната недела. „Над рокот“ се предметите во постапка што го поминале законскиот рок на услугата; „Онлајн“ е делот од предметите за услуги што се нудат онлајн.</p>'
            .'<div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>'.View::e(self::LEVELS[$level]).'</th><th class="num right">Барања / предмети</th><th class="num right">Минатата недела</th><th class="num right">Завршени</th><th class="num right">Одбиени</th><th class="num right">Во постапка</th><th class="num right">Над рокот</th><th class="num right">Online</th><th class="num right">Износ (ден.)</th></tr></thead><tbody>'
            .($html ?: '<tr><td colspan="9">Нема извештаи по услуги за оваа недела.</td></tr>').'</tbody>'
            .($html ? '<tfoot><tr><th scope="row">Вкупно</th>'.$this->cells($totals).'</tr></tfoot>' : '').'</table></div></section>';

        $missing = $catalog->missingSectors($week, $filters['directorate_id'] ? ($scope === null || in_array((int) $filters['directorate_id'], $scope, true) ? [(int) $filters['directorate_id']] : []) : $scope);
        $list = '';
        foreach ($missing as $m) $list .= '<li><strong>'.View::e($m['directorate_code']).'</strong> · '.View::e($m['name']).'</li>';
        $body .= '<section class="card"><h2>Одделенија без извештај за оваа недела</h2>'.($list ? '<ul>'.$list.'</ul>' : '<p class="muted">Сите одделенија известиле.</p>').'</section>';
        $this->view()->render('Извештај по услуги', $body);
    }

    /** GET /citizen-services/catalog : services by directorate and sector, with tiers, reporters and administration. */
    public function catalogPage(): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $manageIds = $this->scope->citizenManageDirectorateIds($this->userId());
        $canManageAny = $manageIds === null || $manageIds !== [];
        $body = $this->nav($this->week(), 'catalog');
        $body .= '<section class="card"><h2>Каталог на услуги</h2><p class="muted">Услугите на општината по сектори и одделенија, со категорија, плаќање, онлајн достапност, законски рок и ниво на известување. Раководителот на секторот ги определува службениците што известуваат за секое одделение; службеникот мора да има активна сметка во е-Кабинет со улога во секторот.'
            .($canManageAny ? ' Администраторот на системот (за сите сектори) и раководителот на секторот (за својот сектор) додаваат, уредуваат, деактивираат и бришат услуги и одделенија. Услугите за кои е известувано не се бришат, туку се деактивираат: историјата се чува и повеќе не се бараат во известувањето.' : '').'</p>'
            .($canManageAny ? '<div class="page-actions"><a class="button" href="/citizen-services/catalog/services/new">Додај услуга</a></div>'.$this->sectorCreateForm($manageIds) : '').'</section>';
        $current = null;
        foreach ($catalog->sectors($this->scope->citizenReportDirectorateIds($this->userId()), $canManageAny) as $sector) {
            $manage = $this->scope->canManageCitizenSectors($this->userId(), (int) $sector['directorate_id']);
            if (!$manage && ((int) $sector['services'] === 0 || !(int) $sector['active'])) continue;
            if ($current !== $sector['directorate_id']) { if ($current !== null) $body .= '</section>'; $current = $sector['directorate_id']; $body .= '<section class="card"><h2>'.View::e($sector['directorate_code'].' · '.$sector['directorate_name']).'</h2>'; }
            $id = (int) $sector['id'];
            $body .= '<h3 id="sector-'.$id.'">'.View::e($sector['name']).' <small class="muted">('.(int) $sector['services'].' активни услуги)</small>'.((int) $sector['active'] ? '' : ' <span class="status-badge status-cancelled">Неактивно</span>').'</h3>';
            $reporters = $catalog->reporters($id);
            $names = array_map(static fn(array $u): string => View::e($u['name']), $reporters);
            $body .= '<p>Известуваат: '.($names ? implode(', ', $names) : '<span class="muted">никој не е определен (известува раководителот на секторот)</span>').'</p>';
            if ($manage) {
                $body .= $this->reporterForms($id, (int) $sector['directorate_id'], $reporters);
                $body .= '<details class="citizen-service-admin"><summary>Администрирање на одделението</summary><div class="inline-actions">'
                    .'<a class="button button-secondary" href="/citizen-services/catalog/services/new?sector_id='.$id.'">Додај услуга во одделението</a>'
                    .'<form method="post" action="/citizen-services/catalog/sectors/'.$id.'" class="inline-actions">'.$this->view()->csrf().'<label>Назив<input name="name" maxlength="250" required value="'.View::e($sector['name']).'"></label><label>Редослед<input type="number" name="sort_order" min="0" max="100000" value="'.(int) $sector['sort_order'].'"></label><button class="button-secondary">Зачувај одделение</button></form>'
                    .'<form method="post" action="/citizen-services/catalog/sectors/'.$id.'/status">'.$this->view()->csrf().'<input type="hidden" name="active" value="'.((int) $sector['active'] ? '0' : '1').'"><button class="button-secondary">'.((int) $sector['active'] ? 'Деактивирај го одделението' : 'Активирај го одделението').'</button></form>'
                    .((int) $sector['all_services'] === 0 ? '<form method="post" action="/citizen-services/catalog/sectors/'.$id.'/delete">'.$this->view()->csrf().'<button class="button-secondary">Избриши го одделението</button></form>' : '')
                    .'</div></details>';
            }
            $rows = '';
            foreach ($this->db->all('SELECT c.*,w.name window_name FROM citizen_service_catalog c JOIN citizen_services w ON w.id=c.window_id WHERE c.sector_id=:sector'.($manage ? '' : ' AND c.active=1').' ORDER BY c.active DESC,c.window_id,c.sort_order', ['sector'=>$id]) as $c) {
                $tier = $manage
                    ? '<form method="post" action="/citizen-services/catalog/'.(int) $c['id'].'/tier" class="inline-actions">'.$this->view()->csrf().'<select name="tier" aria-label="Ниво">'.implode('', array_map(static fn(string $t): string => '<option value="'.$t.'"'.($t === $c['tier'] ? ' selected' : '').'>'.$t.'</option>', array_keys(CitizenServiceCatalog::TIERS))).'</select><button class="button-secondary">Зачувај</button></form>'
                    : View::e($c['tier']);
                $status = (int) $c['active'] ? '' : ' <span class="status-badge status-cancelled">Неактивно</span>';
                $actions = $manage ? '<td><div class="inline-actions"><a class="button button-secondary" href="/citizen-services/catalog/'.(int) $c['id'].'/edit">Уреди</a><form method="post" action="/citizen-services/catalog/'.(int) $c['id'].'/status">'.$this->view()->csrf().'<input type="hidden" name="active" value="'.((int) $c['active'] ? '0' : '1').'"><button class="button-secondary">'.((int) $c['active'] ? 'Деактивирај' : 'Активирај').'</button></form></div></td>' : '';
                $rows .= '<tr><td>'.View::e($c['name']).$status.'<br><small class="muted">'.View::e($c['code']).' · '.View::e($c['window_name']).'</small></td><td>'.View::e(CitizenServiceCatalog::categoryLabel((string) $c['category_code'])).'</td><td>'.View::e($c['beneficiaries'] ?? '').'</td><td>'.((int) $c['fee_required'] ? 'Да' : 'Не').'</td><td>'.((int) $c['online'] ? 'Да' : 'Не').'</td><td>'.($c['legal_deadline_days'] === null ? '—' : (int) $c['legal_deadline_days'].' дена').'</td><td>'.$tier.'</td>'.$actions.'</tr>';
            }
            $body .= '<div class="table-wrap"><table class="data-table"><thead><tr><th>Услуга</th><th>Категорија</th><th>Корисници</th><th>Плаќање</th><th>Online</th><th>Законски рок</th><th>Ниво</th>'.($manage ? '<th>Дејство</th>' : '').'</tr></thead><tbody>'.($rows ?: '<tr><td colspan="'.($manage ? 8 : 7).'">Нема услуги во ова одделение.</td></tr>').'</tbody></table></div>';
        }
        if ($current !== null) $body .= '</section>';
        $legend = '';
        foreach (CitizenServiceCatalog::TIERS as $t => $label) $legend .= '<li>'.View::e($label).'</li>';
        $body .= '<section class="card"><h2>Нивоа на известување</h2><ul>'.$legend.'</ul></section>';
        $this->view()->render('Каталог на услуги', $body);
    }

    /** GET /citizen-services/catalog/services/new?sector_id= and GET /citizen-services/catalog/{id}/edit */
    public function serviceForm(?int $id = null): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $manageIds = $this->scope->citizenManageDirectorateIds($this->userId());
        if ($manageIds === []) throw new HttpException(403, 'Само администраторот на системот или раководителот на секторот го администрира каталогот.');
        $service = $id !== null ? $catalog->service($id) : null;
        if ($id !== null && !$service) throw new HttpException(404, 'Услугата не е пронајдена.');
        if ($service && !$this->scope->canManageCitizenSectors($this->userId(), (int) $service['directorate_id'])) throw new HttpException(403, 'Немате дозвола за оваа услуга.');
        $v = $service ?? ['name'=>'', 'sector_id'=>(int) ($_GET['sector_id'] ?? 0), 'window_id'=>'', 'category_code'=>'', 'beneficiaries'=>'Граѓани', 'fee_required'=>0, 'online'=>0, 'legal_deadline_days'=>45, 'tier'=>'B', 'sort_order'=>''];
        $sectors = '';
        foreach ($catalog->sectors($manageIds) as $s) $sectors .= '<option value="'.(int) $s['id'].'"'.((int) $s['id'] === (int) $v['sector_id'] ? ' selected' : '').'>'.View::e($s['directorate_code'].' · '.$s['name']).'</option>';
        $windows = '';
        foreach ($catalog->windows($manageIds) as $w) $windows .= '<option value="'.(int) $w['id'].'"'.((string) $w['id'] === (string) $v['window_id'] ? ' selected' : '').'>'.View::e($w['directorate_code'].' · '.$w['name'].($w['window_type'] === 'subsidy' ? ' (субвенции)' : '')).'</option>';
        $categories = '';
        foreach (CitizenServiceCatalog::CATEGORIES as $code => [$label]) $categories .= '<option value="'.$code.'"'.($code === $v['category_code'] ? ' selected' : '').'>'.View::e($label).'</option>';
        $tiers = '';
        foreach (CitizenServiceCatalog::TIERS as $t => $label) $tiers .= '<option value="'.$t.'"'.($t === $v['tier'] ? ' selected' : '').'>'.View::e($label).'</option>';
        $action = $service ? '/citizen-services/catalog/'.(int) $service['id'] : '/citizen-services/catalog/services';
        $body = '<div class="page-actions"><a class="button button-secondary" href="/citizen-services/catalog'.($v['sector_id'] ? '#sector-'.(int) $v['sector_id'] : '').'">← Каталог на услуги</a></div>'
            .'<section class="card"><h2>'.($service ? 'Уреди ја услугата '.View::e($service['code']) : 'Нова услуга').'</h2>'
            .'<p class="muted">Шалтерот за известување е групата во која се собираат збировите на секторот; мора да припаѓа на истиот сектор како и одделението. Промените важат од следниот извештај; минатите извештаи остануваат какви што се.</p>'
            .'<form method="post" action="'.$action.'" class="form-grid">'.$this->view()->csrf()
            .'<label class="wide">Назив на услугата<input name="name" maxlength="400" required value="'.View::e($v['name']).'"></label>'
            .'<label>Одделение<select name="sector_id" required><option value="">Изберете одделение</option>'.$sectors.'</select></label>'
            .'<label>Шалтер за известување<select name="window_id" required><option value="">Изберете шалтер</option>'.$windows.'<option value="new">+ Нов шалтер…</option></select></label>'
            .'<label>Назив на новиот шалтер (само за „Нов шалтер“)<input name="new_window_name" maxlength="250"></label>'
            .'<label>Вид на новиот шалтер<select name="new_window_type"><option value="request">Барање</option><option value="subsidy">Субвенции</option></select></label>'
            .'<label>Категорија<select name="category_code" required><option value="">Изберете категорија</option>'.$categories.'</select></label>'
            .'<label>Корисници<input name="beneficiaries" maxlength="200" value="'.View::e($v['beneficiaries'] ?? '').'"></label>'
            .'<label>Законски рок (денови; празно ако нема)<input type="number" name="legal_deadline_days" min="1" max="3650" value="'.View::e($v['legal_deadline_days'] ?? '').'"></label>'
            .'<label>Ниво на известување<select name="tier" required>'.$tiers.'</select></label>'
            .'<label>Редослед<input type="number" name="sort_order" min="0" max="100000" value="'.View::e($v['sort_order']).'"></label>'
            .'<label class="checkbox"><input type="checkbox" name="fee_required" value="1"'.((int) $v['fee_required'] ? ' checked' : '').'> Потребно е плаќање</label>'
            .'<label class="checkbox"><input type="checkbox" name="online" value="1"'.((int) $v['online'] ? ' checked' : '').'> Се нуди онлајн</label>'
            .'<div><button class="primary">'.($service ? 'Зачувај ги промените' : 'Додај ја услугата').'</button></div></form></section>';
        if ($service) {
            $body .= '<section class="card"><h2>Статус</h2><div class="inline-actions"><form method="post" action="/citizen-services/catalog/'.(int) $service['id'].'/status">'.$this->view()->csrf().'<input type="hidden" name="active" value="'.((int) $service['active'] ? '0' : '1').'"><button class="button-secondary">'.((int) $service['active'] ? 'Деактивирај ја услугата' : 'Активирај ја услугата').'</button></form>'
                .'<form method="post" action="/citizen-services/catalog/'.(int) $service['id'].'/delete" class="inline-actions">'.$this->view()->csrf().'<label>Причина за бришење<input name="reason" maxlength="500" required></label><button class="button-secondary">Избриши ја услугата</button></form></div>'
                .'<p class="muted">Се брише само услуга за која никогаш не е известувано; во спротивно се деактивира.</p></section>';
        }
        $this->view()->render($service ? 'Уреди ја услугата' : 'Нова услуга', $body);
    }

    /** POST /citizen-services/catalog/services (new) and POST /citizen-services/catalog/{id} (update) */
    public function serviceSave(?int $id = null): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $before = $id !== null ? $catalog->service($id) : null;
        if ($id !== null && !$before) throw new HttpException(404, 'Услугата не е пронајдена.');
        if ($before) $this->requireManage((int) $before['directorate_id']);
        $sector = $catalog->sector((int) $this->request->input('sector_id', 0));
        if (!$sector) throw new HttpException(422, 'Изберете одделение.');
        $this->requireManage((int) $sector['directorate_id']);
        $data = [];
        foreach (['name', 'sector_id', 'window_id', 'new_window_name', 'new_window_type', 'category_code', 'beneficiaries', 'fee_required', 'online', 'legal_deadline_days', 'tier', 'sort_order'] as $field) $data[$field] = (string) $this->request->input($field, '');
        try { $saved = $catalog->saveService($id, $data); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $after = $catalog->service($saved) ?? [];
        $this->audit->record($this->userId(), $before ? 'citizen_service_catalog.updated' : 'citizen_service_catalog.created', 'CitizenServiceCatalog', $saved, $before ? self::auditable($before) : [], self::auditable($after));
        $this->redirect('/citizen-services/catalog#sector-'.(int) $after['sector_id'], $before ? 'Услугата е изменета.' : 'Услугата е додадена во каталогот.');
    }

    /** POST /citizen-services/catalog/{id}/status (active 0/1) */
    public function serviceStatus(int $id): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $service = $catalog->service($id);
        if (!$service) throw new HttpException(404, 'Услугата не е пронајдена.');
        $this->requireManage((int) $service['directorate_id']);
        $active = (string) $this->request->input('active', '') === '1';
        $catalog->setServiceActive($id, $active);
        $this->audit->record($this->userId(), $active ? 'citizen_service_catalog.activated' : 'citizen_service_catalog.deactivated', 'CitizenServiceCatalog', $id, ['active'=>(int) $service['active']], ['active'=>$active ? 1 : 0]);
        $this->redirect('/citizen-services/catalog#sector-'.(int) $service['sector_id'], $active ? 'Услугата е активирана.' : 'Услугата е деактивирана: повеќе не се бара во известувањето, историјата се чува.');
    }

    /** POST /citizen-services/catalog/{id}/delete (reason) */
    public function serviceDelete(int $id): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $service = $catalog->service($id);
        if (!$service) throw new HttpException(404, 'Услугата не е пронајдена.');
        $this->requireManage((int) $service['directorate_id']);
        $reason = $this->requireField('reason', 500);
        try { $catalog->deleteService($id); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(409, $exception->getMessage()); }
        $this->audit->record($this->userId(), 'citizen_service_catalog.deleted', 'CitizenServiceCatalog', $id, self::auditable($service), [], $reason);
        $this->redirect('/citizen-services/catalog#sector-'.(int) $service['sector_id'], 'Услугата е избришана од каталогот.');
    }

    /** POST /citizen-services/catalog/sectors (directorate_id, name, sort_order) and POST /citizen-services/catalog/sectors/{id} (name, sort_order) */
    public function sectorSave(?int $id = null): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $before = $id !== null ? $catalog->sector($id) : null;
        if ($id !== null && !$before) throw new HttpException(404, 'Одделението не е пронајдено.');
        $directorateId = $before ? (int) $before['directorate_id'] : (int) $this->request->input('directorate_id', 0);
        $this->requireManage($directorateId);
        try { $saved = $catalog->saveSector($id, $directorateId, (string) $this->request->input('name', ''), trim((string) $this->request->input('sort_order', ''))); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $after = $catalog->sector($saved) ?? [];
        $this->audit->record($this->userId(), $before ? 'citizen_service_sector.updated' : 'citizen_service_sector.created', 'CitizenServiceSector', $saved, $before ? ['name'=>$before['name'], 'sort_order'=>$before['sort_order']] : [], ['directorate_id'=>$directorateId, 'code'=>$after['code'] ?? null, 'name'=>$after['name'] ?? null, 'sort_order'=>$after['sort_order'] ?? null]);
        $this->redirect('/citizen-services/catalog#sector-'.$saved, $before ? 'Одделението е изменето.' : 'Одделението е додадено. Сега можете да му додадете услуги.');
    }

    /** POST /citizen-services/catalog/sectors/{id}/status (active 0/1) */
    public function sectorStatus(int $id): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $sector = $catalog->sector($id);
        if (!$sector) throw new HttpException(404, 'Одделението не е пронајдено.');
        $this->requireManage((int) $sector['directorate_id']);
        $active = (string) $this->request->input('active', '') === '1';
        $catalog->setSectorActive($id, $active);
        $this->audit->record($this->userId(), $active ? 'citizen_service_sector.activated' : 'citizen_service_sector.deactivated', 'CitizenServiceSector', $id, ['active'=>(int) $sector['active']], ['active'=>$active ? 1 : 0]);
        $this->redirect('/citizen-services/catalog#sector-'.$id, $active ? 'Одделението е активирано.' : 'Одделението е деактивирано: повеќе не известува, историјата се чува.');
    }

    /** POST /citizen-services/catalog/sectors/{id}/delete */
    public function sectorDelete(int $id): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $sector = $catalog->sector($id);
        if (!$sector) throw new HttpException(404, 'Одделението не е пронајдено.');
        $this->requireManage((int) $sector['directorate_id']);
        try { $catalog->deleteSector($id); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(409, $exception->getMessage()); }
        $this->audit->record($this->userId(), 'citizen_service_sector.deleted', 'CitizenServiceSector', $id, ['code'=>$sector['code'], 'name'=>$sector['name'], 'directorate_id'=>(int) $sector['directorate_id']], []);
        $this->redirect('/citizen-services/catalog', 'Одделението е избришано.');
    }

    private function requireManage(int $directorateId): void
    {
        if (!$this->scope->canManageCitizenSectors($this->userId(), $directorateId)) throw new HttpException(403, 'Само администраторот на системот или раководителот на секторот ги администрира неговите услуги и одделенија.');
    }

    private static function auditable(array $service): array
    {
        return array_intersect_key($service, array_flip(['code', 'name', 'sector_id', 'window_id', 'category_code', 'beneficiaries', 'fee_required', 'online', 'legal_deadline_days', 'tier', 'active', 'sort_order']));
    }

    private function sectorCreateForm(?array $manageIds): string
    {
        $options = '';
        [$where, $params] = $manageIds === null ? ['1=1', []] : ($manageIds === [] ? ['1=0', []] : ['id IN ('.implode(',', array_map('intval', $manageIds)).')', []]);
        foreach ($this->db->all("SELECT id,code,name FROM directorates WHERE active=1 AND {$where} ORDER BY name", $params) as $d) $options .= '<option value="'.(int) $d['id'].'">'.View::e($d['code'].' · '.$d['name']).'</option>';
        return '<details class="citizen-service-admin"><summary>Додај одделение</summary><form method="post" action="/citizen-services/catalog/sectors" class="form-grid">'.$this->view()->csrf()
            .'<label>Сектор<select name="directorate_id" required><option value="">Изберете сектор</option>'.$options.'</select></label><label>Назив на одделението<input name="name" maxlength="250" required></label><label>Редослед<input type="number" name="sort_order" min="0" max="100000"></label><div><button class="primary">Додај го одделението</button></div></form></details>';
    }

    /** POST /citizen-services/catalog/{id}/tier */
    public function tier(int $catalogId): never
    {
        $this->requireAccess();
        $item = $this->db->one('SELECT c.id,c.code,c.tier,c.sector_id,s.directorate_id FROM citizen_service_catalog c JOIN citizen_service_sectors s ON s.id=c.sector_id WHERE c.id=:id', ['id'=>$catalogId]);
        if (!$item) throw new HttpException(404, 'Услугата не е пронајдена.');
        if (!$this->scope->canManageCitizenSectors($this->userId(), (int) $item['directorate_id'])) throw new HttpException(403, 'Само раководителот на секторот или администраторот на системот го менува нивото.');
        $tier = (string) $this->request->input('tier', '');
        if (!isset(CitizenServiceCatalog::TIERS[$tier])) throw new HttpException(422, 'Нивото не е валидно.');
        $this->db->execute('UPDATE citizen_service_catalog SET tier=:tier,updated_at=SYSUTCDATETIME() WHERE id=:id', ['tier'=>$tier, 'id'=>$catalogId]);
        $this->audit->record($this->userId(), 'citizen_service_catalog.tier', 'CitizenServiceCatalog', $catalogId, ['tier'=>$item['tier']], ['tier'=>$tier]);
        $this->redirect('/citizen-services/catalog#sector-'.(int) $item['sector_id'], 'Нивото на услугата е изменето. Важи од следниот извештај на одделението.');
    }

    /** POST /citizen-services/sectors/{id}/reporters (user_id, active) */
    public function reporter(int $sectorId): never
    {
        $this->requireAccess();
        $catalog = $this->catalog();
        $sector = $catalog->sector($sectorId);
        if (!$sector) throw new HttpException(404, 'Одделението не е пронајдено.');
        if (!$this->scope->canManageCitizenSectors($this->userId(), (int) $sector['directorate_id'])) throw new HttpException(403, 'Само раководителот на секторот или администраторот на системот ги определува известувачите.');
        $userId = $this->intField('user_id', 1, PHP_INT_MAX);
        $active = (string) $this->request->input('active', '1') === '1';
        if ($active && !isset($this->candidates((int) $sector['directorate_id'])[$userId])) throw new HttpException(422, 'Корисникот мора да биде активен и да има улога во овој сектор.');
        $catalog->setReporter($sectorId, $userId, $active, $this->userId());
        $this->audit->record($this->userId(), $active ? 'citizen_service_sector.reporter_added' : 'citizen_service_sector.reporter_removed', 'CitizenServiceSector', $sectorId, [], ['user_id'=>$userId]);
        $this->redirect('/citizen-services/catalog#sector-'.$sectorId, $active ? 'Службеникот е определен за известување за одделението.' : 'Службеникот е отстранет од известувањето за одделението.');
    }

    private function sectorForm(CitizenServiceCatalog $catalog, array $sector, string $week): string
    {
        $rows = $catalog->rows((int) $sector['id']);
        $values = $catalog->values((int) $sector['id'], $week);
        $last = null;
        foreach ($values as $v) if ($last === null || (string) $v['submitted_at'] > (string) $last['submitted_at']) $last = $v;
        $html = '<section class="card" id="sector-'.(int) $sector['id'].'"><h2>'.View::e($sector['directorate_code'].' · '.$sector['name']).'</h2>'
            .'<p class="muted">'.($last ? '<span class="status-badge status-active">Пријавено</span> во '.View::e($this->localTime((string) $last['submitted_at'])).' од '.View::e($last['submitted_by_name'] ?? '—') : '<span class="status-badge status-draft">Без извештај за оваа недела</span>').'</p>'
            .'<form method="post" action="/citizen-services/sectors/'.(int) $sector['id'].'/weekly">'.$this->view()->csrf().'<input type="hidden" name="week_start" value="'.View::e($week).'">';
        foreach ($rows as $row) {
            $saved = $values[$row['key']] ?? [];
            $html .= '<fieldset class="citizen-service-row"><legend>'.View::e($row['name']).' <small class="muted">'.View::e(CitizenServiceCatalog::categoryLabel($row['category'])).' · ниво '.View::e($row['tier']).'</small></legend>';
            if ($row['tier'] === 'C' && count($row['services']) > 1) $html .= '<details><summary>'.count($row['services']).' услуги во овој ред</summary><ul>'.implode('', array_map(static fn(string $s): string => '<li>'.View::e($s).'</li>', $row['services'])).'</ul></details>';
            $html .= '<div class="citizen-metric-grid">';
            foreach ($row['fields'] as $field => $label) {
                $name = 'rows['.View::e($row['key']).']['.$field.']';
                $value = $saved[$field] ?? '';
                $html .= $field === 'amount'
                    ? '<label>'.View::e($label).'<input type="number" name="'.$name.'" min="0" step="0.01" inputmode="decimal" value="'.View::e($value === null ? '' : $value).'"></label>'
                    : '<label>'.View::e($label).'<input type="number" name="'.$name.'" min="0" max="1000000000" step="1" inputmode="numeric" value="'.View::e($value === null ? '' : $value).'"></label>';
            }
            $html .= '</div></fieldset>';
        }
        return $html.'<button class="primary">Зачувај го извештајот на одделението</button></form></section>';
    }

    private function reporterForms(int $sectorId, int $directorateId, array $reporters): string
    {
        $assigned = array_flip(array_map('intval', array_column($reporters, 'id')));
        $options = '';
        foreach ($this->candidates($directorateId) as $id => $name) if (!isset($assigned[$id])) $options .= '<option value="'.$id.'">'.View::e($name).'</option>';
        $html = '<div class="inline-actions">';
        if ($options !== '') $html .= '<form method="post" action="/citizen-services/sectors/'.$sectorId.'/reporters" class="inline-actions">'.$this->view()->csrf().'<input type="hidden" name="active" value="1"><select name="user_id" aria-label="Службеник" required><option value="">Изберете службеник</option>'.$options.'</select><button class="button-secondary">Определи известувач</button></form>';
        foreach ($reporters as $u) $html .= '<form method="post" action="/citizen-services/sectors/'.$sectorId.'/reporters">'.$this->view()->csrf().'<input type="hidden" name="active" value="0"><input type="hidden" name="user_id" value="'.(int) $u['id'].'"><button class="button-secondary">Отстрани '.View::e($u['name']).'</button></form>';
        return $html.'</div>';
    }

    /** Active users with a current role in the directorate. */
    private function candidates(int $directorateId): array
    {
        $out = [];
        foreach ($this->db->all("SELECT DISTINCT u.id,u.name FROM users u JOIN user_role_scopes urs ON urs.user_id=u.id WHERE u.status='active' AND urs.directorate_id=:directorate AND (urs.valid_from IS NULL OR urs.valid_from<=SYSUTCDATETIME()) AND (urs.valid_to IS NULL OR urs.valid_to>=SYSUTCDATETIME()) ORDER BY u.name", ['directorate'=>$directorateId]) as $u) $out[(int) $u['id']] = (string) $u['name'];
        return $out;
    }

    private function nav(string $week, string $active): string
    {
        $links = ['reporting'=>['/citizen-services/by-service', 'Известување по услуги'], 'report'=>['/citizen-services/by-service/report', 'Извештај по услуги'], 'catalog'=>['/citizen-services/catalog', 'Каталог на услуги'], 'windows'=>['/citizen-services', 'Неделен извештај на секторите']];
        $html = '<div class="page-actions">';
        foreach ($links as $key => [$href, $label]) $html .= '<a class="button'.($key === $active ? '' : ' button-secondary').'" href="'.$href.'?week_start='.View::e($week).'"'.($key === $active ? ' aria-current="page"' : '').'>'.View::e($label).'</a>';
        $html .= '</div>';
        if ($active !== 'catalog') {
            $query = array_filter(['directorate_id'=>$_GET['directorate_id'] ?? null, 'sector_id'=>$_GET['sector_id'] ?? null, 'category'=>$_GET['category'] ?? null, 'level'=>$_GET['level'] ?? null], static fn($v): bool => is_string($v) && $v !== '');
            $hidden = '';
            foreach ($query as $k => $v) $hidden .= '<input type="hidden" name="'.View::e($k).'" value="'.View::e($v).'">';
            $html .= '<form method="get" class="inline-actions">'.$hidden.'<label>Недела што почнува во понеделник <input type="date" name="week_start" value="'.View::e($week).'" required></label><button class="button button-secondary">Прикажи</button></form>';
        }
        return $html;
    }

    private function cells(array $r): string
    {
        $n = static fn($v): string => number_format((float) $v, 0, ',', '.');
        $change = (float) $r['previous_received'] > 0 ? ' <small class="muted">('.(((float) $r['received'] - (float) $r['previous_received']) >= 0 ? '+' : '').number_format(((float) $r['received'] - (float) $r['previous_received']) / (float) $r['previous_received'] * 100, 0, ',', '').'%)</small>' : '';
        $online = (float) $r['online_base'] > 0 ? number_format((float) $r['online_count'] / (float) $r['online_base'] * 100, 0, ',', '').'%' : '—';
        return '<td class="num right">'.$n($r['received']).$change.'</td><td class="num right">'.$n($r['previous_received']).'</td><td class="num right">'.$n($r['resolved']).'</td><td class="num right">'.$n($r['rejected']).'</td><td class="num right">'.$n($r['open_count']).'</td><td class="num right'.((float) $r['over_deadline'] > 0 ? ' text-danger' : '').'">'.$n($r['over_deadline']).'</td><td class="num right">'.$online.'</td><td class="num right">'.((float) $r['amount'] > 0 ? number_format((float) $r['amount'], 2, ',', '.') : '—').'</td>';
    }

    private function week(): string
    {
        $value = (string) ($_GET['week_start'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) return $date->modify('-'.((int) $date->format('N') - 1).' days')->format('Y-m-d');
        return (new \DateTimeImmutable('monday this week'))->format('Y-m-d');
    }

    private function displayWeek(string $week): string { $d = new \DateTimeImmutable($week); return $d->format('d.m.Y').' – '.$d->modify('+6 days')->format('d.m.Y'); }

    private function localTime(string $utc): string
    {
        try { return (new \DateTimeImmutable(substr($utc, 0, 19), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i'); }
        catch (\Throwable) { return $utc; }
    }
}
