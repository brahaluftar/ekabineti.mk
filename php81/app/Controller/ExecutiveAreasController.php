<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Service\ExecutiveAreas;

/** Report: the policy areas the executive works in at the same time (/executive-areas). */
final class ExecutiveAreasController extends Controller
{
    private const PRESETS = ['today'=>'Денес', 'month'=>'Овој месец', 'quarter'=>'Овој квартал', 'year'=>'Оваа година'];

    private function view(): View { return new View($this->auth, $this->scope); }
    private function service(): ExecutiveAreas { return new ExecutiveAreas($this->db); }
    private function requireAccess(): void { if (!$this->scope->canAccessMayorBriefing($this->userId())) throw new HttpException(403, 'Извештајот за области е за Градоначалникот, кабинетот и администраторите на системот.'); }

    /** GET /executive-areas?preset=|from=&to= */
    public function index(): never
    {
        $this->requireAccess();
        [$from, $to, $preset] = $this->period();
        $service = $this->service();
        $rows = $service->overview($from, $to);
        $q = http_build_query(['from'=>$from, 'to'=>$to]);
        $parallel = array_values(array_filter($rows, static fn(array $r): bool => count($r['directorates']) > 1));
        $totals = ['projects'=>array_sum(array_column($rows, 'projects')), 'areas'=>count(array_filter($rows, static fn(array $r): bool => $r['projects'] + $r['tasks_open'] + $r['cases_open'] + $r['risks_critical'] > 0))];
        $body = $this->periodForm($from, $to, $preset)
            .'<section class="kpi-grid" aria-label="Показатели"><div class="kpi"><span class="kpi__label">Активна област</span><strong class="kpi__value">'.$totals['areas'].'</strong></div><div class="kpi"><span class="kpi__label">Области со повеќе сектори истовремено</span><strong class="kpi__value">'.count($parallel).'</strong></div><div class="kpi"><span class="kpi__label">Активни проекти во периодот</span><strong class="kpi__value">'.$totals['projects'].'</strong></div><div class="kpi"><span class="kpi__label">Период</span><strong class="kpi__value">'.View::e($this->dmy($from).($from !== $to ? ' – '.$this->dmy($to) : '')).'</strong></div></section>';
        $table = '';
        foreach ($rows as $r) {
            $dirs = implode(' ', array_map(static fn(string $c): string => '<span class="status-badge status-draft">'.View::e($c).'</span>', $r['directorates']));
            $table .= '<tr'.(count($r['directorates']) > 1 ? ' class="row-highlight"' : '').'><th scope="row"><a href="/executive-areas/'.(int) $r['area_id'].'?'.View::e($q).'">'.View::e($r['name']).'</a></th><td class="num right"><strong>'.count($r['directorates']).'</strong></td><td>'.($dirs ?: '—').'</td><td class="num right">'.(int) $r['projects'].'</td><td class="num right">'.($r['progress'] === null ? '—' : number_format((float) $r['progress'], 0).'%').'</td><td class="num right">'.(int) $r['tasks_open'].'</td><td class="num right'.($r['tasks_late'] ? ' text-danger' : '').'">'.(int) $r['tasks_late'].'</td><td class="num right'.($r['risks_critical'] ? ' text-danger' : '').'">'.(int) $r['risks_critical'].'</td><td class="num right">'.(int) $r['cases_open'].'</td><td class="num right">'.(int) $r['agenda_open'].'</td><td class="num right">'.($r['budget'] > 0 ? number_format($r['budget'], 0, ',', '.').' ден.' : '—').'</td></tr>';
        }
        $body .= '<section class="card"><h2>Области во кои работи извршната власт</h2><p class="muted">Секоја област со секторите што работат на неа во избраниот период (активни проекти, отворени предмети, критични ризици). Областите со повеќе од еден сектор се истакнати: таму работата се одвива истовремено од повеќе сектори и бара координација.</p>'
            .'<div class="page-actions"><a class="button button-secondary" href="/executive-areas.xlsx?'.View::e($q).'">Извези во Excel</a><a class="button button-secondary" href="/executive-areas/settings">Области и распределба</a></div>'
            .'<div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Област</th><th class="num right">Сектори</th><th>Кој работи</th><th class="num right">Проекти</th><th class="num right">Просечен напредок</th><th class="num right">Отворени задачи</th><th class="num right">Со доцнење</th><th class="num right">Критични ризици</th><th class="num right">Отворени предмети</th><th class="num right">На агендата</th><th class="num right">Буџет '.View::e(substr($to, 0, 4)).'</th></tr></thead><tbody>'.($table ?: '<tr><td colspan="11">Нема активна работа во овој период.</td></tr>').'</tbody></table></div></section>';

        $shared = $service->sharedLocations($from, $to);
        $list = '';
        foreach ($shared as $group) {
            $items = implode('', array_map(fn(array $p): string => '<li><span class="status-badge status-draft">'.View::e($p['directorate_code']).'</span> <a href="/projects/'.(int) $p['id'].'">'.View::e($p['code'].' · '.$p['name']).'</a> <small class="muted">'.View::e($this->span($p)).'</small></li>', $group));
            $list .= '<li><strong>'.View::e($group[0]['location_name']).'</strong><ul>'.$items.'</ul></li>';
        }
        $body .= '<section class="card"><h2>Проекти на повеќе сектори на иста локација</h2><p class="muted">Активни проекти во периодот со иста локација, но од различни сектори (на пр. улица и водовод во иста населба).</p>'.($list ? '<ul class="fact-list">'.$list.'</ul>' : '<p class="muted">Нема преклопување на локации во овој период.</p>').'</section>';
        $this->view()->render('Работни области на извршната власт', $body);
    }

    /** GET /executive-areas/{id}?from=&to= (0 = not assigned) */
    public function area(int $areaId): never
    {
        $this->requireAccess();
        [$from, $to, $preset] = $this->period();
        $service = $this->service();
        $areas = $service->areas(true);
        if ($areaId !== 0 && !isset($service->areas()[$areaId])) throw new HttpException(404, 'Областа не е пронајдена.');
        $name = $areaId === 0 ? ExecutiveAreas::NONE : (string) $service->areas()[$areaId]['name'];
        $projects = $service->areaProjects($areaId, $from, $to);
        $q = http_build_query(['from'=>$from, 'to'=>$to]);
        $body = '<div class="page-actions"><a class="button button-secondary" href="/executive-areas?'.View::e($q).'">← Сите области</a></div>'.$this->periodForm($from, $to, $preset, '/executive-areas/'.$areaId);
        $body .= '<section class="card"><h2>'.View::e($name).'</h2><p class="muted">'.count($projects).' активни проекти од '.count(array_unique(array_column($projects, 'directorate_code'))).' сектори во периодот '.View::e($this->dmy($from).($from !== $to ? ' – '.$this->dmy($to) : '')).'.</p>'.$this->timeline($projects, $from, $to).'</section>';
        $options = static function (?int $selected) use ($areas): string {
            $html = '<option value="">Според секторот</option>';
            foreach ($areas as $id => $a) $html .= '<option value="'.$id.'"'.($selected === $id ? ' selected' : '').'>'.View::e($a['name']).'</option>';
            return $html;
        };
        $rows = '';
        foreach ($projects as $p) {
            $rows .= '<tr><td><a href="/projects/'.(int) $p['id'].'"><strong>'.View::e($p['code']).'</strong> · '.View::e($p['name']).'</a>'.($p['location_name'] ? '<br><small class="muted">'.View::e($p['location_name']).'</small>' : '').'</td><td>'.View::e($p['directorate_code']).'</td><td>'.View::e($this->span($p)).'</td><td class="num right">'.(int) $p['progress'].'%</td><td class="num right">'.(int) $p['tasks_open'].'</td><td class="num right'.($p['tasks_late'] ? ' text-danger' : '').'">'.(int) $p['tasks_late'].'</td><td class="num right'.($p['risks_critical'] ? ' text-danger' : '').'">'.(int) $p['risks_critical'].'</td>'
                .'<td><form method="post" action="/executive-areas/projects/'.(int) $p['id'].'" class="inline-actions">'.$this->view()->csrf().'<input type="hidden" name="return" value="/executive-areas/'.$areaId.'?'.View::e($q).'"><select name="policy_area_id" aria-label="Област на проектот">'.$options($p['policy_area_id'] === null ? null : (int) $p['policy_area_id']).'</select><button class="button-secondary">Зачувај</button></form></td></tr>';
        }
        $body .= '<section class="card"><h2>Проекти</h2><p class="muted">Областа на проектот се зема од секторот; променете ја тука кога проектот припаѓа на друга област (на пр. училиште изградено од Капитални инвестиции → Образование).</p><div class="table-wrap table-wrap--wide"><table class="data-table"><thead><tr><th>Проект</th><th>Сектор</th><th>Период</th><th class="num right">Напредок</th><th class="num right">Отворени задачи</th><th class="num right">Со доцнење</th><th class="num right">Критични ризици</th><th>Област</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="8">Нема активни проекти.</td></tr>').'</tbody></table></div></section>';
        $cases = '';
        foreach (array_slice($service->areaCases($areaId, $to), 0, 100) as $c) $cases .= '<tr><td><a href="'.((int) ($c['is_legal'] ?? 0) ? '/legal-office/cases/' : '/cases/').(int) $c['id'].'">'.View::e($c['case_number']).'</a></td><td>'.View::e($c['case_type']).'</td><td>'.View::e($c['directorate_code']).'</td><td>'.View::e($c['status']).'</td><td>'.View::e($c['priority']).'</td><td>'.View::e($c['opened_at'] ? $this->dmy(substr((string) $c['opened_at'], 0, 10)) : '—').'</td></tr>';
        $body .= '<section class="card"><h2>Отворени предмети</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Број</th><th>Вид</th><th>Сектор</th><th>Статус</th><th>Приоритет</th><th>Отворен на</th></tr></thead><tbody>'.($cases ?: '<tr><td colspan="6">Нема отворени предмети.</td></tr>').'</tbody></table></div></section>';
        $this->view()->render('Област: '.$name, $body);
    }

    /** GET /executive-areas.xlsx?from=&to= */
    public function export(): never
    {
        $this->requireAccess();
        [$from, $to] = $this->period();
        $service = $this->service();
        $overview = array_map(static fn(array $r): array => ['area'=>$r['name'], 'count'=>count($r['directorates']), 'directorates'=>implode(', ', $r['directorates']), 'projects'=>$r['projects'], 'progress'=>$r['progress'], 'tasks_open'=>$r['tasks_open'], 'tasks_late'=>$r['tasks_late'], 'risks'=>$r['risks_critical'], 'cases'=>$r['cases_open'], 'agenda'=>$r['agenda_open'], 'budget'=>$r['budget']], $service->overview($from, $to));
        $projects = [];
        foreach ($service->overview($from, $to) as $r) foreach ($service->areaProjects((int) $r['area_id'], $from, $to) as $p) $projects[] = ['area'=>$r['name'], 'directorate'=>$p['directorate_code'], 'code'=>$p['code'], 'name'=>$p['name'], 'location'=>$p['location_name'], 'starts'=>$p['starts_at'] ? substr((string) $p['starts_at'], 0, 10) : '', 'ends'=>$p['ends_at'] ? substr((string) $p['ends_at'], 0, 10) : '', 'progress'=>(int) $p['progress'], 'tasks_open'=>(int) $p['tasks_open'], 'tasks_late'=>(int) $p['tasks_late'], 'risks'=>(int) $p['risks_critical']];
        (new \EKabineti\Service\TabularExport())->xlsx('e-kabineti-fushat-e-punes-'.$from.'_'.$to, [
            ['name'=>'Области', 'headers'=>['area'=>'Област', 'count'=>'Сектори', 'directorates'=>'Кој работи', 'projects'=>'Проекти', 'progress'=>'Просечен напредок (%)', 'tasks_open'=>'Отворени задачи', 'tasks_late'=>'Со доцнење', 'risks'=>'Критични ризици', 'cases'=>'Отворени предмети', 'agenda'=>'На агендата', 'budget'=>'Буџет (ден.)'], 'rows'=>$overview],
            ['name'=>'Проекти', 'headers'=>['area'=>'Област', 'directorate'=>'Сектор', 'code'=>'Шифра', 'name'=>'Проект', 'location'=>'Локација', 'starts'=>'Почеток', 'ends'=>'Завршување', 'progress'=>'Напредок (%)', 'tasks_open'=>'Отворени задачи', 'tasks_late'=>'Со доцнење', 'risks'=>'Критични ризици'], 'rows'=>$projects],
        ]);
    }

    /** GET /executive-areas/settings: areas and the default area of each directorate. */
    public function settings(): never
    {
        $this->requireAccess();
        $service = $this->service();
        $areas = $service->areas();
        $rows = '';
        foreach ($areas as $id => $a) $rows .= '<tr><td><form method="post" action="/executive-areas/areas/'.$id.'" class="inline-actions">'.$this->view()->csrf().'<input name="name" maxlength="150" required value="'.View::e($a['name']).'" aria-label="Назив"><input type="number" name="sort_order" min="0" max="100000" value="'.(int) $a['sort_order'].'" aria-label="Редослед"><label class="checkbox"><input type="checkbox" name="active" value="1"'.((int) $a['active'] ? ' checked' : '').'> Активни</label><button class="button-secondary">Зачувај</button></form></td></tr>';
        $active = $service->areas(true);
        $dirRows = '';
        foreach ($this->db->all('SELECT id,code,name,policy_area_id FROM directorates WHERE active=1 ORDER BY name') as $d) {
            $opts = '<option value="">— Без област —</option>';
            foreach ($active as $id => $a) $opts .= '<option value="'.$id.'"'.((int) $d['policy_area_id'] === $id ? ' selected' : '').'>'.View::e($a['name']).'</option>';
            $dirRows .= '<tr><td><strong>'.View::e($d['code']).'</strong> · '.View::e($d['name']).'</td><td><form method="post" action="/executive-areas/directorates/'.(int) $d['id'].'" class="inline-actions">'.$this->view()->csrf().'<select name="policy_area_id" aria-label="Област">'.$opts.'</select><button class="button-secondary">Зачувај</button></form></td></tr>';
        }
        $body = '<div class="page-actions"><a class="button button-secondary" href="/executive-areas">← Работни области</a></div>'
            .'<section class="card"><h2>Нова област</h2><form method="post" action="/executive-areas/areas" class="inline-actions">'.$this->view()->csrf().'<label>Назив<input name="name" maxlength="150" required></label><label>Редослед<input type="number" name="sort_order" min="0" max="100000"></label><button class="primary">Додај област</button></form></section>'
            .'<section class="card"><h2>Области</h2><p class="muted">Неактивната област повеќе не се избира за сектори или проекти; претходно доделената работа останува поврзана со неа.</p><div class="table-wrap"><table class="data-table"><tbody>'.$rows.'</tbody></table></div></section>'
            .'<section class="card"><h2>Област на секторите</h2><p class="muted">Област во која се брои работата на секторот, освен проектите што имаат своја област.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Сектор</th><th>Област</th></tr></thead><tbody>'.$dirRows.'</tbody></table></div></section>';
        $this->view()->render('Области и распределба', $body);
    }

    /** POST /executive-areas/areas (name, sort_order) and /executive-areas/areas/{id} (name, sort_order, active) */
    public function areaSave(?int $id = null): never
    {
        $this->requireAccess();
        try {
            if ($id === null) $id = $this->service()->createArea((string) $this->request->input('name', ''), trim((string) $this->request->input('sort_order', '')));
            else $this->service()->updateArea($id, (string) $this->request->input('name', ''), trim((string) $this->request->input('sort_order', '')), (string) $this->request->input('active', '') === '1');
        } catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $this->audit->record($this->userId(), 'policy_area.saved', 'PolicyArea', $id, [], ['name'=>$this->request->input('name', ''), 'active'=>$this->request->input('active', '1')]);
        $this->redirect('/executive-areas/settings', 'Областа е зачувана.');
    }

    /** POST /executive-areas/directorates/{id} and /executive-areas/projects/{id} (policy_area_id, empty = none / by directorate) */
    public function assign(string $table, int $id): never
    {
        $this->requireAccess();
        $area = (int) $this->request->input('policy_area_id', 0) ?: null;
        $before = $this->db->scalar($table === 'projects' ? 'SELECT policy_area_id FROM projects WHERE id=:id' : 'SELECT policy_area_id FROM directorates WHERE id=:id', ['id'=>$id]);
        try { $this->service()->assign($table, $id, $area); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $this->audit->record($this->userId(), $table === 'projects' ? 'project.policy_area' : 'directorate.policy_area', $table === 'projects' ? 'App\\Models\\Project' : 'App\\Models\\Directorate', $id, ['policy_area_id'=>$before], ['policy_area_id'=>$area]);
        $return = (string) $this->request->input('return', '');
        $this->redirect(str_starts_with($return, '/executive-areas') && !str_contains($return, '//') ? $return : '/executive-areas/settings', 'Областа е доделена.');
    }

    /** SVG timeline of the projects (bars from start to end, today marked). No inline styles: classes and attributes. */
    private function timeline(array $projects, string $from, string $to): string
    {
        $dated = array_values(array_filter($projects, static fn(array $p): bool => $p['starts_at'] || $p['ends_at']));
        if ($dated === []) return '';
        $start = min(array_map(static fn(array $p): string => substr((string) ($p['starts_at'] ?: $p['ends_at']), 0, 10), $dated)); $start = min($start, $from);
        $end = max(array_map(static fn(array $p): string => substr((string) ($p['ends_at'] ?: $p['starts_at']), 0, 10), $dated)); $end = max($end, $to);
        $t0 = strtotime($start); $span = max(86400, strtotime($end) - $t0);
        $width = 900; $label = 260; $rowH = 22; $height = count($dated) * $rowH + 30;
        $x = static fn(string $date): float => $label + ($width - $label - 10) * (strtotime($date) - $t0) / $span;
        $svg = '<svg class="area-timeline" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="Календар на проекти">';
        $svg .= '<rect class="area-timeline__period" x="'.round($x($from), 1).'" y="0" width="'.round(max(2, $x($to) - $x($from)), 1).'" height="'.$height.'"><title>Избран период</title></rect>';
        foreach ($dated as $i => $p) {
            $y = 10 + $i * $rowH;
            $s = substr((string) ($p['starts_at'] ?: $from), 0, 10); $e = substr((string) ($p['ends_at'] ?: $end), 0, 10);
            $svg .= '<text class="area-timeline__label" x="4" y="'.($y + 13).'">'.View::e(mb_strimwidth($p['directorate_code'].' · '.$p['code'].' '.$p['name'], 0, 42, '…')).'</text>'
                .'<rect class="area-timeline__bar'.($p['tasks_late'] ? ' area-timeline__bar--late' : '').'" x="'.round($x($s), 1).'" y="'.$y.'" width="'.round(max(3, $x($e) - $x($s)), 1).'" height="16" rx="3"><title>'.View::e($p['code'].' · '.$this->span($p).' · '.(int) $p['progress'].'%').'</title></rect>';
        }
        $today = date('Y-m-d');
        if ($today >= $start && $today <= $end) $svg .= '<line class="area-timeline__today" x1="'.round($x($today), 1).'" x2="'.round($x($today), 1).'" y1="0" y2="'.$height.'"><title>Денес</title></line>';
        $svg .= '<text class="area-timeline__label" x="'.$label.'" y="'.($height - 4).'">'.View::e($this->dmy($start)).'</text><text class="area-timeline__label" x="'.($width - 80).'" y="'.($height - 4).'">'.View::e($this->dmy($end)).'</text>';
        return '<div class="table-wrap">'.$svg.'</svg></div>';
    }

    private function period(): array
    {
        $preset = (string) ($_GET['preset'] ?? '');
        $today = new \DateTimeImmutable('today');
        if (isset(self::PRESETS[$preset])) {
            return match ($preset) {
                'today'=>[$today->format('Y-m-d'), $today->format('Y-m-d'), $preset],
                'month'=>[$today->format('Y-m-01'), $today->format('Y-m-t'), $preset],
                'quarter'=>(static function () use ($today): array { $q = intdiv((int) $today->format('n') - 1, 3); $s = $today->setDate((int) $today->format('Y'), $q * 3 + 1, 1); return [$s->format('Y-m-d'), $s->modify('+2 months')->format('Y-m-t'), 'quarter']; })(),
                default=>[$today->format('Y-01-01'), $today->format('Y-12-31'), $preset],
            };
        }
        $valid = static fn(string $v): bool => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) !== false && $d->format('Y-m-d') === $v;
        $from = (string) ($_GET['from'] ?? ''); $to = (string) ($_GET['to'] ?? '');
        if (!$valid($from) || !$valid($to)) return [$today->format('Y-m-d'), $today->format('Y-m-d'), 'today'];
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to, ''];
    }

    private function periodForm(string $from, string $to, string $preset, string $action = '/executive-areas'): string
    {
        $links = '';
        foreach (self::PRESETS as $key => $label) $links .= '<a class="button'.($key === $preset ? '' : ' button-secondary').'" href="'.$action.'?preset='.$key.'"'.($key === $preset ? ' aria-current="page"' : '').'>'.$label.'</a>';
        return '<div class="page-actions">'.$links.'<form method="get" action="'.$action.'" class="inline-actions"><label>Од <input type="date" name="from" value="'.View::e($from).'" required></label><label>До <input type="date" name="to" value="'.View::e($to).'" required></label><button class="button button-secondary">Прикажи</button></form></div>';
    }

    private function span(array $p): string { return ($p['starts_at'] ? $this->dmy(substr((string) $p['starts_at'], 0, 10)) : '—').' – '.($p['ends_at'] ? $this->dmy(substr((string) $p['ends_at'], 0, 10)) : 'без рок'); }
    private function dmy(string $iso): string { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso); return $d ? $d->format('d.m.Y') : $iso; }
}
