<?php

declare(strict_types=1);

namespace EKabineti\Service;

use EKabineti\Core\Database;

/**
 * Catalog of the municipal services and their weekly report, filled by the sector heads / responsible officers.
 *
 * Tier A and B services are reported one by one; tier C services are reported together, one row per sector, category
 * and window ("Të tjera – Pëlqime"). Each category has its own fields. The totals of the reporting window
 * (citizen_services) the services belong to are computed from these rows, so the directorate totals
 * (vw_citizen_service_directorate_weeks), the Mayor's dashboard and the weekly briefing read them as before.
 */
final class CitizenServiceCatalog
{
    public const FIELDS = ['received', 'resolved', 'rejected', 'open_count', 'over_deadline', 'online_count', 'amount'];

    /** category => [label, fields: column => label]. over_deadline and online_count are added per service. */
    public const CATEGORIES = [
        'certificates'=>['Уверенија, потврди и изводи', ['resolved'=>'Издадени', 'rejected'=>'Одбиени']],
        'registrations'=>['Регистрации', ['received'=>'Барање', 'resolved'=>'Регистрирани', 'rejected'=>'Одбиени', 'open_count'=>'Во постапка']],
        'marriage'=>['Венчавки', ['resolved'=>'Одржани церемонии', 'rejected'=>'Откажани']],
        'permits'=>['Дозволи (за градење, еколошки, патни)', ['received'=>'Апликации', 'resolved'=>'Издадени дозволи', 'rejected'=>'Одбиени', 'open_count'=>'Во постапка']],
        'consents'=>['Согласности', ['received'=>'Барање', 'resolved'=>'Дадени согласности', 'rejected'=>'Одбиени', 'open_count'=>'Во постапка']],
        'public_space'=>['Користење јавна површина и реклами', ['received'=>'Апликации', 'resolved'=>'Дадени дозволи', 'rejected'=>'Одбиени', 'open_count'=>'Во постапка']],
        'decisions'=>['Решенија и управни барања', ['received'=>'Барање', 'resolved'=>'Решенија', 'rejected'=>'Одбиени', 'open_count'=>'Во постапка']],
        'subsidies'=>['Субвенции, стипендии и финансиска поддршка', ['received'=>'Апликации', 'resolved'=>'Одобрени', 'rejected'=>'Одбиени', 'open_count'=>'Во разгледување', 'amount'=>'Одобрен износ (ден.)']],
        'property_tax'=>['Данок на имот', ['received'=>'Барање', 'resolved'=>'Завршени', 'open_count'=>'Во постапка']],
        'municipal_property'=>['Општински имот (користење / замена)', ['received'=>'Апликации', 'resolved'=>'Договори / решенија', 'open_count'=>'Отворени постапки']],
    ];

    public const TIERS = ['A'=>'A · над 1.000 годишно, поединечно', 'B'=>'B · 100–999 годишно, поединечно', 'C'=>'C · под 100 годишно, заедно по категорија'];

    public function __construct(private readonly Database $db) {}

    public static function categoryLabel(string $code): string { return self::CATEGORIES[$code][0] ?? $code; }

    /** Fields of a report row: the category fields, "Mbi afatin ligjor" when there is an open count and a deadline, "Prej tyre online" for online services. */
    public static function fields(string $category, bool $online, ?int $deadline): array
    {
        $fields = self::CATEGORIES[$category][1] ?? [];
        $out = [];
        foreach ($fields as $column => $label) {
            $out[$column] = $label;
            if ($column === 'open_count' && $deadline !== null) $out['over_deadline'] = 'Од нив над законскиот рок ('.$deadline.' дена)';
        }
        if ($online) {
            $amount = $out['amount'] ?? null; unset($out['amount']);
            $out['online_count'] = 'Од нив онлајн';
            if ($amount !== null) $out['amount'] = $amount;
        }
        return $out;
    }

    /** Sectors with their directorate (all, or of the given directorates), with the number of active services. */
    public function sectors(?array $directorateIds = null, bool $includeInactive = false): array
    {
        [$where, $params] = self::inList('s.directorate_id', $directorateIds, 'dir');
        $active = $includeInactive ? '1=1' : 's.active=1';
        return $this->db->all("SELECT s.id,s.code,s.name,s.active,s.sort_order,s.directorate_id,d.code directorate_code,d.name directorate_name,(SELECT COUNT(*) FROM citizen_service_catalog c WHERE c.sector_id=s.id AND c.active=1) services,(SELECT COUNT(*) FROM citizen_service_catalog c WHERE c.sector_id=s.id) all_services FROM citizen_service_sectors s JOIN directorates d ON d.id=s.directorate_id WHERE {$active} AND {$where} ORDER BY d.name,s.sort_order,s.name", $params);
    }

    // --- Administration of the catalog: services, sectors and their reporting windows. ---------------------------

    public function service(int $id): ?array
    {
        return $this->db->one('SELECT c.*,s.directorate_id,s.name sector_name,w.name window_name FROM citizen_service_catalog c JOIN citizen_service_sectors s ON s.id=c.sector_id JOIN citizen_services w ON w.id=c.window_id WHERE c.id=:id', ['id'=>$id]);
    }

    /** Reporting windows a service can belong to: active request and subsidy windows of the directorates. */
    public function windows(?array $directorateIds = null): array
    {
        [$where, $params] = self::inList('w.directorate_id', $directorateIds, 'win');
        return $this->db->all("SELECT w.id,w.code,w.name,w.window_type,w.directorate_id,d.code directorate_code FROM citizen_services w JOIN directorates d ON d.id=w.directorate_id WHERE w.active=1 AND w.window_type IN ('request','subsidy') AND {$where} ORDER BY d.code,w.sort_order,w.name", $params);
    }

    /**
     * Creates ($id null) or updates a service. $d: name, sector_id, window_id (or 'new' with new_window_name and
     * new_window_type), category_code, beneficiaries, fee_required, online, legal_deadline_days, tier, sort_order.
     * The window must belong to the directorate of the sector. Returns the service id.
     */
    public function saveService(?int $id, array $d): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($d['name'] ?? '')));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 400) throw new \InvalidArgumentException('Називот на услугата мора да има од 3 до 400 знаци.');
        $sector = $this->db->one('SELECT s.*,d.code directorate_code FROM citizen_service_sectors s JOIN directorates d ON d.id=s.directorate_id WHERE s.id=:id', ['id'=>(int) ($d['sector_id'] ?? 0)]);
        if (!$sector) throw new \InvalidArgumentException('Изберете одделение.');
        $category = (string) ($d['category_code'] ?? '');
        if (!isset(self::CATEGORIES[$category])) throw new \InvalidArgumentException('Категоријата не е валидна.');
        $tier = (string) ($d['tier'] ?? '');
        if (!isset(self::TIERS[$tier])) throw new \InvalidArgumentException('Нивото не е валидно.');
        $deadlineRaw = trim((string) ($d['legal_deadline_days'] ?? ''));
        if ($deadlineRaw !== '' && (!ctype_digit($deadlineRaw) || (int) $deadlineRaw < 1 || (int) $deadlineRaw > 3650)) throw new \InvalidArgumentException('Законскиот рок мора да биде од 1 до 3650 дена, или празно кога нема рок.');
        $beneficiaries = trim((string) ($d['beneficiaries'] ?? ''));
        if (mb_strlen($beneficiaries) > 200) throw new \InvalidArgumentException('Корисниците се предолги.');
        $sort = trim((string) ($d['sort_order'] ?? ''));
        if ($sort !== '' && (!ctype_digit($sort) || (int) $sort > 100000)) throw new \InvalidArgumentException('Редоследот мора да биде број од 0 до 100000.');
        $duplicate = $this->db->scalar('SELECT COUNT(*) FROM citizen_service_catalog WHERE sector_id=:sector AND name=:name AND id<>:id', ['sector'=>$sector['id'], 'name'=>$name, 'id'=>$id ?? 0]);
        if ((int) $duplicate > 0) throw new \InvalidArgumentException('Ова одделение веќе има услуга со ова име.');

        return (int) $this->db->transaction(function () use ($id, $d, $name, $sector, $category, $tier, $deadlineRaw, $beneficiaries, $sort): int {
            $windowInput = (string) ($d['window_id'] ?? '');
            if ($windowInput === 'new') {
                $windowId = $this->createWindow((int) $sector['directorate_id'], (string) ($d['new_window_name'] ?? ''), (string) ($d['new_window_type'] ?? 'request'));
            } else {
                $window = $this->db->one("SELECT id,directorate_id FROM citizen_services WHERE id=:id AND active=1 AND window_type IN ('request','subsidy')", ['id'=>(int) $windowInput]);
                if (!$window) throw new \InvalidArgumentException('Изберете шалтер за известување или создадете нов.');
                if ((int) $window['directorate_id'] !== (int) $sector['directorate_id']) throw new \InvalidArgumentException('Шалтерот за известување мора да припаѓа на истиот сектор како одделението.');
                $windowId = (int) $window['id'];
            }
            $params = ['sector'=>(int) $sector['id'], 'window'=>$windowId, 'name'=>$name, 'category'=>$category, 'beneficiaries'=>$beneficiaries !== '' ? $beneficiaries : null, 'fee'=>!empty($d['fee_required']) ? 1 : 0, 'online'=>!empty($d['online']) ? 1 : 0, 'deadline'=>$deadlineRaw === '' ? null : (int) $deadlineRaw, 'tier'=>$tier];
            if ($id !== null) {
                $params['sort'] = $sort === '' ? (int) $this->db->scalar('SELECT sort_order FROM citizen_service_catalog WHERE id=:id', ['id'=>$id]) : (int) $sort;
                $this->db->execute('UPDATE citizen_service_catalog SET sector_id=:sector,window_id=:window,name=:name,category_code=:category,beneficiaries=:beneficiaries,fee_required=:fee,online=:online,legal_deadline_days=:deadline,tier=:tier,sort_order=:sort,updated_at=SYSUTCDATETIME() WHERE id=:id', $params + ['id'=>$id]);
                return $id;
            }
            $params['sort'] = $sort === '' ? (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+10 FROM citizen_service_catalog WHERE window_id=:window', ['window'=>$windowId]) : (int) $sort;
            return $this->db->insertId('INSERT INTO citizen_service_catalog(code,sector_id,window_id,name,category_code,beneficiaries,fee_required,online,legal_deadline_days,tier,active,sort_order,created_at,updated_at) VALUES(:code,:sector,:window,:name,:category,:beneficiaries,:fee,:online,:deadline,:tier,1,:sort,SYSUTCDATETIME(),SYSUTCDATETIME())', $params + ['code'=>$this->nextServiceCode()]);
        });
    }

    public function setServiceActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE citizen_service_catalog SET active=:active,updated_at=SYSUTCDATETIME() WHERE id=:id', ['active'=>$active ? 1 : 0, 'id'=>$id]);
    }

    /** Deletes a service that was never reported; a reported one can only be deactivated (its history stays). */
    public function deleteService(int $id): void
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM citizen_service_item_reports WHERE catalog_id=:id', ['id'=>$id]) > 0) throw new \InvalidArgumentException('Услугата има неделни извештаи и не може да се избрише. Деактивирајте ја: историјата се чува и повеќе не се бара.');
        $service = $this->service($id);
        if ($service && $service['tier'] === 'C' && (int) $this->db->scalar("SELECT COUNT(*) FROM citizen_service_item_reports WHERE catalog_id IS NULL AND sector_id=:sector AND window_id=:window AND category_code=:category", ['sector'=>$service['sector_id'], 'window'=>$service['window_id'], 'category'=>$service['category_code']]) > 0) throw new \InvalidArgumentException('Услугата е пријавена во групата „Други“ и не може да се избрише. Деактивирајте ја.');
        $this->db->execute('DELETE FROM citizen_service_catalog WHERE id=:id', ['id'=>$id]);
    }

    /** Creates ($id null) or renames / reorders a sector. Returns the sector id. */
    public function saveSector(?int $id, int $directorateId, string $name, string $sort = ''): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 250) throw new \InvalidArgumentException('Називот на одделението мора да има од 3 до 250 знаци.');
        if ($sort !== '' && (!ctype_digit($sort) || (int) $sort > 100000)) throw new \InvalidArgumentException('Редоследот мора да биде број од 0 до 100000.');
        $directorate = $this->db->one('SELECT id,code FROM directorates WHERE id=:id AND active=1', ['id'=>$directorateId]);
        if (!$directorate) throw new \InvalidArgumentException('Секторот не е пронајден или е неактивен.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM citizen_service_sectors WHERE directorate_id=:directorate AND name=:name AND id<>:id', ['directorate'=>$directorateId, 'name'=>$name, 'id'=>$id ?? 0]) > 0) throw new \InvalidArgumentException('Секторот веќе има одделение со ова име.');
        if ($id !== null) {
            $this->db->execute('UPDATE citizen_service_sectors SET name=:name,sort_order=COALESCE(:sort,sort_order) WHERE id=:id', ['name'=>$name, 'sort'=>$sort === '' ? null : (int) $sort, 'id'=>$id]);
            return $id;
        }
        $code = $this->uniqueCode('citizen_service_sectors', $directorate['code'].'_SEK');
        $order = $sort === '' ? (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+10 FROM citizen_service_sectors WHERE directorate_id=:directorate', ['directorate'=>$directorateId]) : (int) $sort;
        return $this->db->insertId('INSERT INTO citizen_service_sectors(directorate_id,code,name,active,sort_order,created_at) VALUES(:directorate,:code,:name,1,:sort,SYSUTCDATETIME())', ['directorate'=>$directorateId, 'code'=>$code, 'name'=>$name, 'sort'=>$order]);
    }

    public function setSectorActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE citizen_service_sectors SET active=:active WHERE id=:id', ['active'=>$active ? 1 : 0, 'id'=>$id]);
    }

    /** Deletes a sector without services and reports (its reporter assignments go with it). */
    public function deleteSector(int $id): void
    {
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM citizen_service_catalog WHERE sector_id=:id', ['id'=>$id]) > 0) throw new \InvalidArgumentException('Одделението има услуги. Преместете ги или избришете ги услугите, или деактивирајте го одделението.');
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM citizen_service_item_reports WHERE sector_id=:id', ['id'=>$id]) > 0) throw new \InvalidArgumentException('Одделението има неделни извештаи и не може да се избрише. Деактивирајте го.');
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('DELETE FROM citizen_service_sector_reporters WHERE sector_id=:id', ['id'=>$id]);
            $this->db->execute('DELETE FROM citizen_service_sectors WHERE id=:id', ['id'=>$id]);
        });
    }

    /** New reporting window of a directorate (request or subsidy) with the standard fields. */
    public function createWindow(int $directorateId, string $name, string $type): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 250) throw new \InvalidArgumentException('Називот на новиот шалтер мора да има од 3 до 250 знаци.');
        if (!in_array($type, ['request', 'subsidy'], true)) throw new \InvalidArgumentException('Видот на шалтерот не е валиден.');
        $code = (string) $this->db->scalar('SELECT code FROM directorates WHERE id=:id', ['id'=>$directorateId]);
        if ($code === '') throw new \InvalidArgumentException('Секторот не е пронајден.');
        $windowId = $this->db->insertId("INSERT INTO citizen_services(directorate_id,code,name,description,sla_target_hours,active,sort_order,window_type,created_at,updated_at) VALUES(:directorate,:code,:name,:description,NULL,1,:sort,:type,SYSUTCDATETIME(),SYSUTCDATETIME())", ['directorate'=>$directorateId, 'code'=>$this->uniqueCode('citizen_services', $code.'_SRV'), 'name'=>$name, 'description'=>'Се пресметува од известувањето по услуги.', 'sort'=>(int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0)+10 FROM citizen_services WHERE directorate_id=:directorate', ['directorate'=>$directorateId]), 'type'=>$type]);
        $fields = $type === 'subsidy'
            ? [['received', 'Примени апликации', 'received'], ['open', 'Во разгледување', 'open'], ['resolved', 'Одобрени', 'resolved'], ['rejected', 'Одбиени', 'resolved'], ['approved_amount', 'Одобрен износ (ден.)', 'amount']]
            : [['received', 'Примени барања', 'received'], ['reviewed', 'Разгледани барања', 'informational'], ['resolved', 'Завршени барања', 'resolved'], ['open', 'Во постапка', 'open']];
        foreach ($fields as $i => [$key, $label, $role]) $this->db->execute('INSERT INTO citizen_service_metrics(service_id,metric_key,label,metric_role,active,sort_order,created_at,updated_at) VALUES(:service,:key,:label,:role,1,:sort,SYSUTCDATETIME(),SYSUTCDATETIME())', ['service'=>$windowId, 'key'=>$key, 'label'=>$label, 'role'=>$role, 'sort'=>($i + 1) * 10]);
        return $windowId;
    }

    private function nextServiceCode(): string
    {
        $max = 0;
        foreach ($this->db->all("SELECT code FROM citizen_service_catalog WHERE code LIKE 'CS%'") as $row) if (preg_match('/^CS(\d+)/', (string) $row['code'], $m)) $max = max($max, (int) $m[1]);
        return sprintf('CS%03d', $max + 1);
    }

    private function uniqueCode(string $table, string $prefix): string
    {
        if (!in_array($table, ['citizen_service_sectors', 'citizen_services'], true)) throw new \LogicException('Unsafe table.');
        $codes = array_flip(array_column($this->db->all("SELECT code FROM {$table} WHERE code LIKE :prefix", ['prefix'=>$prefix.'%']), 'code'));
        for ($i = 1; isset($codes[$prefix.$i]); $i++);
        return $prefix.$i;
    }

    public function sector(int $sectorId): ?array
    {
        return $this->db->one('SELECT s.*,d.code directorate_code,d.name directorate_name FROM citizen_service_sectors s JOIN directorates d ON d.id=s.directorate_id WHERE s.id=:id', ['id'=>$sectorId]);
    }

    /** Report rows of a sector: tier A/B one per service, tier C one per category and window. */
    public function rows(int $sectorId): array
    {
        $sector = $this->sector($sectorId);
        $rows = [];
        foreach ($this->db->all('SELECT c.*,w.code window_code FROM citizen_service_catalog c JOIN citizen_services w ON w.id=c.window_id WHERE c.sector_id=:sector AND c.active=1 ORDER BY c.window_id,c.sort_order,c.id', ['sector'=>$sectorId]) as $c) {
            $deadline = $c['legal_deadline_days'] === null ? null : (int) $c['legal_deadline_days'];
            if ($c['tier'] !== 'C') {
                $rows['S:'.$c['code']] = ['key'=>'S:'.$c['code'], 'catalog_id'=>(int) $c['id'], 'window_id'=>(int) $c['window_id'], 'category'=>(string) $c['category_code'], 'name'=>(string) $c['name'], 'tier'=>(string) $c['tier'], 'services'=>[(string) $c['name']], 'online'=>(bool) $c['online'], 'deadline'=>$deadline, 'fee'=>(bool) $c['fee_required']];
                continue;
            }
            $key = 'C:'.$sector['code'].':'.$c['category_code'].':'.$c['window_code'];
            $rows[$key] ??= ['key'=>$key, 'catalog_id'=>null, 'window_id'=>(int) $c['window_id'], 'category'=>(string) $c['category_code'], 'name'=>'Други – '.self::categoryLabel((string) $c['category_code']), 'tier'=>'C', 'services'=>[], 'online'=>false, 'deadline'=>null, 'fee'=>false];
            $rows[$key]['services'][] = (string) $c['name'];
            $rows[$key]['online'] = $rows[$key]['online'] || (bool) $c['online'];
            $rows[$key]['fee'] = $rows[$key]['fee'] || (bool) $c['fee_required'];
            if ($deadline !== null) $rows[$key]['deadline'] = $rows[$key]['deadline'] === null ? $deadline : min($rows[$key]['deadline'], $deadline);
        }
        foreach ($rows as &$row) {
            if ($row['tier'] === 'C' && count($row['services']) === 1) $row['name'] = $row['services'][0];
            $row['fields'] = self::fields($row['category'], $row['online'], $row['deadline']);
        }
        unset($row);
        // Tier C groups after the services of the same window.
        uasort($rows, static fn(array $a, array $b): int => [$a['window_id'], $a['tier'] === 'C' ? 1 : 0] <=> [$b['window_id'], $b['tier'] === 'C' ? 1 : 0]);
        return array_values($rows);
    }

    /** Saved values of a sector for a week, by row key, with who and when. */
    public function values(int $sectorId, string $week): array
    {
        $out = [];
        foreach ($this->db->all('SELECT r.*,u.name submitted_by_name FROM citizen_service_item_reports r LEFT JOIN users u ON u.id=r.submitted_by WHERE r.sector_id=:sector AND r.week_start=:week', ['sector'=>$sectorId, 'week'=>$week]) as $row) $out[(string) $row['row_key']] = $row;
        return $out;
    }

    /**
     * Validates and stores the weekly numbers of a sector ($input[rowKey][field]) and recomputes the totals of its
     * windows. Empty fields count as 0. Returns the stored rows and the windows recomputed.
     */
    public function save(int $sectorId, string $week, array $input, int $userId): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $week);
        if (!$date || $date->format('Y-m-d') !== $week || $date->format('N') !== '1') throw new \InvalidArgumentException('Неделата мора да почнува во понеделник.');
        $rows = $this->rows($sectorId);
        if ($rows === []) throw new \InvalidArgumentException('Одделението нема активни услуги.');
        $clean = [];
        foreach ($rows as $row) {
            $given = is_array($input[$row['key']] ?? null) ? $input[$row['key']] : [];
            $values = array_fill_keys(self::FIELDS, null);
            foreach ($row['fields'] as $field => $label) {
                $raw = str_replace(' ', '', trim((string) ($given[$field] ?? '')));
                if ($raw === '') $raw = '0';
                // Counts: "1.200" or "1,200" is 1200. Amounts: "12.500,50" (Albanian) or "12500.50" (browser).
                if ($field !== 'amount') $raw = str_replace(['.', ','], '', $raw);
                elseif (str_contains($raw, ',')) $raw = str_replace(['.', ','], ['', '.'], $raw);
                if ($field === 'amount') {
                    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $raw)) throw new \InvalidArgumentException($row['name'].': "'.$label.'“ не е валиден износ.');
                    $values[$field] = number_format((float) $raw, 2, '.', '');
                } else {
                    if (!preg_match('/^\d{1,10}$/', $raw) || (int) $raw > 1000000000) throw new \InvalidArgumentException($row['name'].': "'.$label.'“ мора да биде позитивен цел број.');
                    $values[$field] = (int) $raw;
                }
            }
            if ($values['over_deadline'] !== null && $values['over_deadline'] > (int) $values['open_count']) throw new \InvalidArgumentException($row['name'].': предметите над законскиот рок не можат да бидат повеќе од оние во постапка.');
            if ($values['online_count'] !== null && $values['online_count'] > max((int) $values['received'], (int) $values['resolved'] + (int) $values['rejected'])) throw new \InvalidArgumentException($row['name'].': онлајн предметите не можат да бидат повеќе од вкупниот број.');
            $clean[$row['key']] = $row + ['values'=>$values];
        }
        $windows = [];
        $this->db->transaction(function () use ($clean, $sectorId, $week, $userId, &$windows): void {
            foreach ($clean as $key => $row) {
                $v = $row['values'];
                $params = ['received'=>$v['received'], 'resolved'=>$v['resolved'], 'rejected'=>$v['rejected'], 'open'=>$v['open_count'], 'over'=>$v['over_deadline'], 'online'=>$v['online_count'], 'amount'=>$v['amount'], 'user'=>$userId];
                $existing = $this->db->scalar('SELECT id FROM citizen_service_item_reports WITH (UPDLOCK,HOLDLOCK) WHERE row_key=:key AND week_start=:week', ['key'=>$key, 'week'=>$week]);
                if ($existing) $this->db->execute('UPDATE citizen_service_item_reports SET received=:received,resolved=:resolved,rejected=:rejected,open_count=:open,over_deadline=:over,online_count=:online,amount=:amount,submitted_by=:user,submitted_at=SYSUTCDATETIME() WHERE id=:id', $params + ['id'=>$existing]);
                else $this->db->execute('INSERT INTO citizen_service_item_reports(row_key,week_start,sector_id,window_id,catalog_id,category_code,received,resolved,rejected,open_count,over_deadline,online_count,amount,submitted_by,submitted_at) VALUES(:key,:week,:sector,:window,:catalog,:category,:received,:resolved,:rejected,:open,:over,:online,:amount,:user,SYSUTCDATETIME())', $params + ['key'=>$key, 'week'=>$week, 'sector'=>$sectorId, 'window'=>$row['window_id'], 'catalog'=>$row['catalog_id'], 'category'=>$row['category']]);
                $windows[$row['window_id']] = true;
            }
            // Rows of this week that no longer exist (service deactivated, moved or tier changed) are dropped, so nothing counts twice.
            foreach ($this->db->all('SELECT id,row_key,window_id FROM citizen_service_item_reports WHERE sector_id=:sector AND week_start=:week', ['sector'=>$sectorId, 'week'=>$week]) as $old) {
                if (isset($clean[(string) $old['row_key']])) continue;
                $this->db->execute('DELETE FROM citizen_service_item_reports WHERE id=:id', ['id'=>$old['id']]);
                $windows[(int) $old['window_id']] = true;
            }
            foreach (array_keys($windows) as $windowId) $this->recomputeWindow((int) $windowId, $week, $userId);
        });
        return ['rows'=>count($clean), 'windows'=>array_keys($windows)];
    }

    /** Window totals from the rows of its services: received, reviewed/resolved (finished), rejected, open, amount. */
    public function recomputeWindow(int $windowId, string $week, int $userId): void
    {
        $window = $this->db->one('SELECT id,directorate_id,window_type FROM citizen_services WHERE id=:id', ['id'=>$windowId]);
        if (!$window) return;
        $t = $this->db->one('SELECT COUNT(*) n,SUM(COALESCE(received,COALESCE(resolved,0)+COALESCE(rejected,0))) received,SUM(COALESCE(resolved,0)) resolved,SUM(COALESCE(rejected,0)) rejected,SUM(COALESCE(open_count,0)) open_count,SUM(COALESCE(amount,0)) amount FROM citizen_service_item_reports WHERE window_id=:window AND week_start=:week', ['window'=>$windowId, 'week'=>$week]) ?? [];
        if ((int) ($t['n'] ?? 0) === 0) {
            $this->db->execute("DELETE FROM citizen_service_weekly_reports WHERE service_id=:service AND week_start=:week AND notes LIKE 'Llogaritur%'", ['service'=>$windowId, 'week'=>$week]);
            return;
        }
        $finished = (int) $t['resolved'] + (int) $t['rejected'];
        $subsidy = $window['window_type'] === 'subsidy';
        $byKey = ['received'=>(int) $t['received'], 'reviewed'=>$finished, 'resolved'=>$subsidy ? (int) $t['resolved'] : $finished, 'rejected'=>(int) $t['rejected'], 'unfounded'=>0, 'open'=>(int) $t['open_count']];
        $reportId = $this->db->scalar('SELECT id FROM citizen_service_weekly_reports WITH (UPDLOCK,HOLDLOCK) WHERE service_id=:service AND week_start=:week', ['service'=>$windowId, 'week'=>$week]);
        $note = 'Пресметано од извештаите на одделенијата по услуги.';
        if ($reportId) $this->db->execute('UPDATE citizen_service_weekly_reports SET notes=:notes,submitted_by=:user,submitted_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=:id', ['notes'=>$note, 'user'=>$userId, 'id'=>$reportId]);
        else $reportId = $this->db->insertId('INSERT INTO citizen_service_weekly_reports(service_id,directorate_id,week_start,average_resolution_hours,notes,submitted_by,submitted_at,updated_at) VALUES(:service,:directorate,:week,NULL,:notes,:user,SYSUTCDATETIME(),SYSUTCDATETIME())', ['service'=>$windowId, 'directorate'=>(int) $window['directorate_id'], 'week'=>$week, 'notes'=>$note, 'user'=>$userId]);
        $this->db->execute('DELETE FROM citizen_service_weekly_values WHERE report_id=:report', ['report'=>$reportId]);
        foreach ($this->db->all('SELECT id,metric_key,metric_role FROM citizen_service_metrics WHERE service_id=:service AND active=1', ['service'=>$windowId]) as $m) {
            $amount = $m['metric_role'] === 'amount' ? number_format((float) $t['amount'], 2, '.', '') : null;
            $this->db->execute('INSERT INTO citizen_service_weekly_values(report_id,metric_id,metric_value,amount_value,created_at,updated_at) VALUES(:report,:metric,:value,:amount,SYSUTCDATETIME(),SYSUTCDATETIME())', ['report'=>$reportId, 'metric'=>$m['id'], 'value'=>$amount === null ? ($byKey[(string) $m['metric_key']] ?? 0) : 0, 'amount'=>$amount]);
        }
    }

    /** Windows filled from the catalog (their totals are computed, not typed). */
    public function computedWindowIds(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT DISTINCT window_id FROM citizen_service_catalog WHERE active=1'), 'window_id'));
    }

    /**
     * Weekly report grouped by directorate, sector, category or service, with the week before for comparison.
     * $filters: directorate_id, sector_id, category; $level: directorate|sector|category|service.
     */
    public function summary(string $week, string $level, array $filters = [], ?array $directorateIds = null): array
    {
        $group = match ($level) {
            'directorate'=>['d.id', 'd.code', 'd.name'],
            'sector'=>['s.id', 's.code', 's.name'],
            'category'=>['r.category_code', 'r.category_code', 'r.category_code'],
            default=>['r.row_key', 'r.row_key', 'COALESCE(c.name,r.row_key)'],
        };
        [$scope, $params] = self::inList('s.directorate_id', $directorateIds, 'sum');
        $where = $scope;
        if (!empty($filters['directorate_id'])) { $where .= ' AND s.directorate_id=:f_directorate'; $params['f_directorate'] = (int) $filters['directorate_id']; }
        if (!empty($filters['sector_id'])) { $where .= ' AND r.sector_id=:f_sector'; $params['f_sector'] = (int) $filters['sector_id']; }
        if (!empty($filters['category'])) { $where .= ' AND r.category_code=:f_category'; $params['f_category'] = (string) $filters['category']; }
        $previous = (new \DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d');
        $sql = "SELECT {$group[0]} k,MIN({$group[1]}) code,MIN({$group[2]}) name,MIN(d.code) directorate_code,MIN(r.category_code) category_code,MIN(c.legal_deadline_days) deadline,
                SUM(CASE WHEN r.week_start=:week THEN COALESCE(r.received,COALESCE(r.resolved,0)+COALESCE(r.rejected,0)) ELSE 0 END) received,
                SUM(CASE WHEN r.week_start=:week2 THEN COALESCE(r.resolved,0) ELSE 0 END) resolved,
                SUM(CASE WHEN r.week_start=:week3 THEN COALESCE(r.rejected,0) ELSE 0 END) rejected,
                SUM(CASE WHEN r.week_start=:week4 THEN COALESCE(r.open_count,0) ELSE 0 END) open_count,
                SUM(CASE WHEN r.week_start=:week5 THEN COALESCE(r.over_deadline,0) ELSE 0 END) over_deadline,
                SUM(CASE WHEN r.week_start=:week6 THEN COALESCE(r.online_count,0) ELSE 0 END) online_count,
                SUM(CASE WHEN r.week_start=:week7 AND r.online_count IS NOT NULL THEN COALESCE(r.received,COALESCE(r.resolved,0)+COALESCE(r.rejected,0)) ELSE 0 END) online_base,
                SUM(CASE WHEN r.week_start=:week8 THEN COALESCE(r.amount,0) ELSE 0 END) amount,
                SUM(CASE WHEN r.week_start=:previous THEN COALESCE(r.received,COALESCE(r.resolved,0)+COALESCE(r.rejected,0)) ELSE 0 END) previous_received
            FROM citizen_service_item_reports r
            JOIN citizen_service_sectors s ON s.id=r.sector_id
            JOIN directorates d ON d.id=s.directorate_id
            LEFT JOIN citizen_service_catalog c ON c.id=r.catalog_id
            WHERE r.week_start IN (:week9,:previous2) AND {$where}
            GROUP BY {$group[0]}
            ORDER BY SUM(CASE WHEN r.week_start=:week10 THEN COALESCE(r.received,COALESCE(r.resolved,0)+COALESCE(r.rejected,0)) ELSE 0 END) DESC";
        $weekParams = [];
        foreach (['week', 'week2', 'week3', 'week4', 'week5', 'week6', 'week7', 'week8', 'week9', 'week10'] as $name) $weekParams[$name] = $week;
        $rows = $this->db->all($sql, $params + $weekParams + ['previous'=>$previous, 'previous2'=>$previous]);
        if ($level === 'category') foreach ($rows as &$row) $row['name'] = self::categoryLabel((string) $row['code']); unset($row);
        if ($level === 'service') foreach ($rows as &$row) if (str_starts_with((string) $row['code'], 'C:')) $row['name'] = 'Други – '.self::categoryLabel((string) $row['category_code']).' ('.explode(':', (string) $row['code'])[1].')'; unset($row);
        return $rows;
    }

    /** Sectors with active services that did not report the week. */
    public function missingSectors(string $week, ?array $directorateIds = null): array
    {
        [$where, $params] = self::inList('s.directorate_id', $directorateIds, 'miss');
        return $this->db->all("SELECT s.id,s.name,d.code directorate_code FROM citizen_service_sectors s JOIN directorates d ON d.id=s.directorate_id WHERE s.active=1 AND {$where} AND EXISTS (SELECT 1 FROM citizen_service_catalog c WHERE c.sector_id=s.id AND c.active=1) AND NOT EXISTS (SELECT 1 FROM citizen_service_item_reports r WHERE r.sector_id=s.id AND r.week_start=:week) ORDER BY d.code,s.sort_order", $params + ['week'=>$week]);
    }

    /** Active reporters of a sector. */
    public function reporters(int $sectorId): array
    {
        return $this->db->all('SELECT u.id,u.name,u.email FROM citizen_service_sector_reporters r JOIN users u ON u.id=r.user_id WHERE r.sector_id=:sector AND r.active=1 ORDER BY u.name', ['sector'=>$sectorId]);
    }

    public function setReporter(int $sectorId, int $userId, bool $active, int $by): void
    {
        $existing = $this->db->scalar('SELECT id FROM citizen_service_sector_reporters WHERE sector_id=:sector AND user_id=:user', ['sector'=>$sectorId, 'user'=>$userId]);
        if ($existing) $this->db->execute('UPDATE citizen_service_sector_reporters SET active=:active,assigned_by=:by,assigned_at=SYSUTCDATETIME() WHERE id=:id', ['active'=>$active ? 1 : 0, 'by'=>$by, 'id'=>$existing]);
        elseif ($active) $this->db->execute('INSERT INTO citizen_service_sector_reporters(sector_id,user_id,active,assigned_by,assigned_at) VALUES(:sector,:user,1,:by,SYSUTCDATETIME())', ['sector'=>$sectorId, 'user'=>$userId, 'by'=>$by]);
    }

    private static function inList(string $column, ?array $ids, string $prefix): array
    {
        if ($ids === null) return ['1=1', []];
        if ($ids === []) return ['1=0', []];
        $marks = []; $params = [];
        foreach (array_values($ids) as $i => $id) { $marks[] = ':'.$prefix.$i; $params[$prefix.$i] = (int) $id; }
        return [$column.' IN ('.implode(',', $marks).')', $params];
    }
}
