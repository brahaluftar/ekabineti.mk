<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Daily revenue report of the municipal treasury account (one row per payment, no header):
 * Nr. rendor · Shuma · Përfituesi · Paguesi · Grupi (10/20/30/40) · Përshkrimi · Data · UNIREF.
 *
 * parse() keeps every source value as it is (raw_*) and adds what can be read from it: the UNIREF parts, payer name and
 * type, personal or business number, invoice number, property number, licence plate, case reference, payment channel and
 * revenue category. Every extracted value is a reading of the source, never a replacement of it.
 */
final class RevenueReport
{
    public const COLUMNS = ['raw_row_number','raw_amount','raw_beneficiary','raw_payer','raw_revenue_group','raw_description','raw_payment_date','raw_uniref'];

    public const GROUPS = ['10'=>'Данок на имот', '20'=>'Здравство', '30'=>'Образование', '40'=>'Други општински приходи'];

    /** Categories (code => [name, group]) and the UNIREF type rules seeded by the migration; production edits them in /revenues/categories. */
    public const CATEGORIES = [
        'property_tax'=>['Данок на имот','10'],
        'health'=>['Здравство – партиципации и уверенија','20'],
        'edu_kindergarten'=>['Образование – предучилишни установи (градинки)','30'],
        'edu_halls'=>['Образование – користење спортски сали','30'],
        'edu_duplicates'=>['Образование – дупликати од дипломи и свидетелства','30'],
        'edu_other'=>['Образование – други','30'],
        'vehicle_registration'=>['Општинска такса за регистрација на возила','40'],
        'cadastre_data'=>['Катастар – податоци и уверенија','40'],
        'cadastre_services'=>['Катастар – трансакции и услуги','40'],
        'traffic_fines'=>['Казни – сообраќај и паркирање','40'],
        'vehicle_impound'=>['Одземање возила','40'],
        'public_space'=>['Користење на јавна површина и јавен имот','40'],
        'waste'=>['Надомест за собирање отпад','40'],
        'building_permits'=>['Урбанизам – одобренија за градење','40'],
        'legalisation'=>['Урбанизам – легализација','40'],
        'urban_services'=>['Урбанизам – информации и услуги','40'],
        'infrastructure_fee'=>['Надомест за уредување на градежно земјиште','40'],
        'rent'=>['Закупнина за општински имот','40'],
        'civil_registry'=>['Матична евиденција и управни услуги','40'],
        'library'=>['Култура – библиотека','40'],
        'archive'=>['Архив – техничка документација','40'],
        'business_permits'=>['Бизнис – дозволи и такси','40'],
        'environment_permits'=>['Еколошки дозволи','40'],
        'unclassified'=>['Некласифицирани',null],
    ];

    /** [UNIREF type prefix, keyword in the description or null, category code, priority] – the lowest priority wins. */
    public const RULES = [
        ['B2',null,'property_tax',100], ['B4',null,'vehicle_registration',100], ['BM',null,'waste',100], ['MM',null,'waste',100],
        ['GF',null,'cadastre_data',100], ['G4',null,'cadastre_services',100], ['N9',null,'traffic_fines',100], ['M5',null,'vehicle_impound',100],
        ['M8',null,'public_space',100], ['H8',null,'health',100], ['Q4',null,'civil_registry',100], ['Q4','PLAN','urban_services',50],
        ['R8',null,'library',100], ['R4',null,'archive',100], ['U4',null,'building_permits',100], ['UA',null,'legalisation',100],
        ['UB',null,'business_permits',100], ['E6',null,'business_permits',100], ['UL',null,'environment_permits',100],
        ['D8',null,'infrastructure_fee',100], ['A8',null,'rent',100],
        ['F1',null,'edu_kindergarten',100], ['F2',null,'edu_kindergarten',100], ['F3',null,'edu_kindergarten',100], ['F4',null,'edu_kindergarten',100],
        ['F5',null,'edu_kindergarten',100], ['F6',null,'edu_kindergarten',100], ['F7',null,'edu_kindergarten',100], ['F8',null,'edu_kindergarten',100],
        ['F9',null,'edu_duplicates',100], ['FA',null,'edu_duplicates',100], ['FB',null,'edu_duplicates',100],
        ['FP',null,'edu_halls',100], ['FS',null,'edu_halls',100], ['F','SALL','edu_halls',60], ['F',null,'edu_other',200],
    ];

    /** Group (column 5) => category when no UNIREF rule applies. */
    private const GROUP_FALLBACK = ['10'=>'property_tax', '20'=>'health', '30'=>'edu_other'];

    private const UNIREF = '/^([A-Z]{2})([A-Z0-9]{2})([A-Z])([A-Z0-9]{10})([A-Z0-9])$/';
    private const INTERMEDIARIES = '/EKOSOVA|^TEB\b|POSTA E KOSOV|BANKA|BANK\b|PRIBANK|RAIFFEISEN|PROCREDIT|^NLB|^BKT|KOMUNA E PRISHT|^P\.?K\.?$/i';
    private const BUSINESS = '/\b(SH\.?\s?P\.?\s?K\.?|SH\.?\s?A\.?|L\.?\s?L\.?\s?C\.?|B\.?\s?I\.?|N\.?\s?T\.?\s?P\.?|O\.?\s?J\.?\s?Q\.?|EOOD|GROUP|COMPANY|KLUBI|SHOQ[ËE]RIA|Q\.?\s?K\.?\s?M\.?\s?F?\.?|AGJENCIA|KORPORATA|INSTITUT\w*|OP)\b/iu';

    /**
     * Reads the report rows (arrays of cell values). Returns ['rows'=>[...], 'fatal'=>[...]]; each row has the raw values,
     * the extracted fields, 'errors' (the row is not imported) and 'warnings' (imported, to be reviewed).
     */
    /**
     * Whole report in memory (tests and small files). Large reports go through stream(), row by row.
     */
    public static function parse(iterable $rows, array $rules = []): array
    {
        $state = self::newState();
        $out = iterator_to_array(self::stream($rows, $rules, $state), false);
        $similar = [];
        foreach ($out as $index => $row) if ($row['content_key'] !== null) $similar[$row['loose_key']][$row['content_key']] = true;
        foreach ($out as &$row) if ($row['content_key'] !== null && count($similar[$row['loose_key']]) > 1) $row['warnings'][] = 'possible_duplicate';
        unset($row);
        return ['rows'=>$out, 'fatal'=>self::fatal($state)];
    }

    /** Counters shared by stream() and fatal(): rows read, rows without errors, content keys seen. */
    public static function newState(): array
    {
        return ['read'=>0, 'valid'=>0, 'header_skipped'=>false, 'seen'=>[]];
    }

    /**
     * Reads the report row by row (memory stays flat for 300 000 rows). Each row gets source_key = content key plus "#n"
     * for the n-th identical row of the file, and loose_key: the content in upper case without extra spaces. Rows with
     * the same loose_key but different content keys differ only in case or spacing (possible double payment); stream()
     * cannot know that before the end, so the caller marks them (parse() in memory, RevenueImport in SQL).
     */
    public static function stream(iterable $rows, array $rules, array &$state): \Generator
    {
        $rules = $rules ?: self::defaultRules();
        foreach ($rows as $index => $values) {
            $values = array_values(array_map(static fn($v): string => trim((string) $v), (array) $values));
            if (!array_filter($values, static fn(string $v): bool => $v !== '')) continue;
            if (!$state['header_skipped'] && $state['read'] === 0 && self::normalizeAmount($values[1] ?? '') === '' && preg_match('/shuma|amount|uniref|data/i', implode(' ', $values))) { $state['header_skipped'] = true; continue; }
            $state['read']++;
            $raw = [];
            foreach (self::COLUMNS as $i => $column) $raw[$column] = self::clip($values[$i] ?? '', $column === 'raw_description' ? 2000 : 300);
            $row = ['source_row_number'=>(int) $index + 1] + $raw + self::extract($raw, $rules);
            if ($row['errors'] !== []) {
                $row += ['source_key'=>null, 'content_key'=>null, 'loose_key'=>null, 'occurrence'=>0];
            } else {
                $state['valid']++;
                $key = self::contentKey($row);
                $occurrence = $state['seen'][$key] = ($state['seen'][$key] ?? 0) + 1;
                $loose = [$row['payment_date'], $row['amount']];
                foreach (['raw_beneficiary','raw_payer','raw_revenue_group','raw_description','raw_uniref'] as $column) $loose[] = mb_strtoupper(self::squash((string) $row[$column]));
                $row += ['source_key'=>'rev:'.$key.($occurrence > 1 ? '#'.$occurrence : ''), 'content_key'=>$key, 'loose_key'=>hash('sha256', implode("\x1F", $loose)), 'occurrence'=>$occurrence];
            }
            yield $row;
        }
    }

    /** empty_file, or unexpected_layout when fewer than half of the rows have a valid date and amount. */
    public static function fatal(array $state): array
    {
        if ($state['read'] === 0) return [['row'=>1, 'code'=>'empty_file']];
        if ($state['valid'] < max(1, (int) floor($state['read'] / 2))) return [['row'=>1, 'code'=>'unexpected_layout']];
        return [];
    }

    /** One row: parsed date and amount, UNIREF parts, the fields found in the payer and description, category. */
    public static function extract(array $raw, array $rules = []): array
    {
        $rules = $rules ?: self::defaultRules();
        $errors = []; $warnings = []; $confidence = [];
        $date = self::normalizeDate($raw['raw_payment_date']);
        if ($date === '') $errors[] = 'invalid_date';
        $amount = self::normalizeAmount($raw['raw_amount']);
        if ($amount === '' || (float) $amount <= 0) { $errors[] = 'invalid_amount'; $amount = ''; }
        $group = preg_match('/^\s*(10|20|30|40)(?:\.0+)?\s*$/', $raw['raw_revenue_group'], $m) ? $m[1] : null;

        $description = self::squash(html_entity_decode($raw['raw_description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $payer = self::squash(html_entity_decode($raw['raw_payer'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $upper = mb_strtoupper($description.' | '.$payer);

        $uniref = self::uniref($raw['raw_uniref']);
        if ($uniref === null && preg_match('/\b([A-Z]{2}[A-Z0-9]{2}[A-Z][A-Z0-9]{11})\b/', $upper, $m) && ($found = self::uniref($m[1])) !== null) { $uniref = $found; $confidence['uniref'] = 'description'; }
        $type = $uniref['type'] ?? null;
        if ($uniref === null) {
            // A cut-off UNIREF (e.g. "PRB4A") still names the municipality and the type of revenue.
            $partial = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw['raw_uniref']));
            if (preg_match('/^([A-Z]{2})([A-Z][A-Z0-9])/', $partial, $m)) { $type = $m[2]; $warnings[] = 'incomplete_uniref'; }
            else $warnings[] = 'invalid_uniref';
        } elseif ($uniref['municipality'] !== 'PR') $warnings[] = 'other_municipality';

        [$name, $nameSource] = self::payerName($payer, $description, $uniref['uniref'] ?? null);
        if ($name !== null) $confidence['payer_name'] = $nameSource;
        [$identification, $identificationType, $identificationSource] = self::identification($upper, $uniref);
        if ($identification !== null) $confidence['identification_number'] = $identificationSource;
        [$invoice, $invoiceSource] = self::invoice($upper, $payer, $uniref, $group);
        if ($invoice !== null) $confidence['invoice_number'] = $invoiceSource;
        [$category, $method] = self::classify($type, $upper, $group, $rules);
        $property = self::property($upper);
        [$taxpayer, $taxpayerSource] = self::taxpayer($upper, $category === 'property_tax' ? ($uniref['reference'] ?? '') : null);
        if ($taxpayer !== null) $confidence['taxpayer_number'] = $taxpayerSource;
        $plate = self::plate($upper);
        $case = self::caseReference($upper);
        if ($category === 'unclassified') $warnings[] = 'unclassified';

        return [
            'payment_date'=>$date, 'amount'=>$amount, 'revenue_group'=>$group,
            'uniref'=>$uniref['uniref'] ?? null, 'uniref_valid'=>$uniref !== null ? 1 : 0, 'uniref_municipality'=>$uniref['municipality'] ?? null,
            'uniref_type'=>$type, 'uniref_subtype'=>$uniref['subtype'] ?? null, 'uniref_reference'=>$uniref['reference'] ?? null,
            'uniref_generic'=>!empty($uniref['generic']) ? 1 : 0,
            'payer_name'=>$name, 'payer_type'=>$name === null ? 'unknown' : (preg_match(self::BUSINESS, $name) ? 'business' : 'individual'),
            'identification_number'=>$identification, 'identification_type'=>$identificationType,
            'invoice_number'=>$invoice, 'taxpayer_number'=>$taxpayer, 'property_number'=>$property, 'plate_number'=>$plate, 'case_reference'=>$case,
            'channel'=>self::channel($payer, $description), 'category_code'=>$category, 'classification_method'=>$method,
            'extraction'=>$confidence, 'errors'=>$errors, 'warnings'=>$warnings,
        ];
    }

    /**
     * Identity of a payment: every source value exactly as exported (only surrounding spaces are ignored), except the
     * statement row number and the Excel row, which change between exports. Texts typed differently ("Shpk" / "shpk")
     * are different payment orders, so they are not merged.
     */
    public static function contentKey(array $row): string
    {
        $parts = [$row['payment_date'], $row['amount'] === '' ? '' : number_format((float) $row['amount'], 2, '.', '')];
        foreach (['raw_beneficiary','raw_payer','raw_revenue_group','raw_description','raw_uniref'] as $column) $parts[] = trim((string) $row[$column]);
        return hash('sha256', implode("\x1F", $parts));
    }

    public static function uniref(string $value): ?array
    {
        $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        if (!preg_match(self::UNIREF, $value, $m)) return null;
        return ['uniref'=>$value, 'municipality'=>$m[1], 'type'=>$m[2], 'subtype'=>$m[3], 'reference'=>$m[4], 'check'=>$m[5],
            // Generic references (1111111111, 0000000000) are shared by all payers of a service; they identify the service only.
            'generic'=>preg_match('/^(\d)\1{9}$/', $m[4]) === 1];
    }

    public static function classify(?string $type, string $text, ?string $group, array $rules): array
    {
        $best = null;
        foreach ($rules as $rule) {
            [$prefix, $keyword, $category, $priority] = [(string) $rule[0], $rule[1] ?? null, (string) $rule[2], (int) ($rule[3] ?? 100)];
            if ($type === null || !str_starts_with($type, $prefix)) continue;
            if ($keyword !== null && $keyword !== '' && !str_contains($text, mb_strtoupper($keyword))) continue;
            $rank = [$priority, -strlen($prefix)];
            if ($best === null || $rank < $best[0]) $best = [$rank, $category, $keyword !== null && $keyword !== '' ? 'keyword' : 'uniref'];
        }
        if ($best !== null) return [$best[1], $best[2]];
        if ($group !== null && isset(self::GROUP_FALLBACK[$group])) return [self::GROUP_FALLBACK[$group], 'group'];
        return ['unclassified', 'none'];
    }

    public static function defaultRules(): array { return self::RULES; }

    private static function payerName(string $payer, string $description, ?string $uniref): array
    {
        if (self::looksLikeName($payer) && !preg_match(self::INTERMEDIARIES, $payer)) {
            $name = trim((string) preg_replace('/\s*\b\d{6,}\b.*$/', '', $payer));
            if (self::looksLikeName($name)) return [self::cleanName($name), 'payer'];
        }
        $patterns = [
            '/EMRI\s*\/\s*MBIEMRI\s*:\s*([\p{L}\' ]{4,80})/iu',
            '/^EKOSOVA\s+\d{2}\s+([\p{L}\' .]{4,80}?)\s+\d{5,}/iu',
            '/^TEB SH\.?A\.?\s+\d+\s+[A-Z0-9]{16}\s+\d{9,10}\s+([\p{L}\' ]{4,80}?)\s+PRISHTIN\p{L}*\s*$/iu',
            '/^\s*([\p{L}\' ]{4,80}?)\s+[A-Z]{2}[A-Z0-9]{2}[A-Z][A-Z0-9 ]{10,12}\b/u',
            '/^(?:KOMUNA\s*-\s*PRISHTINA|KOMUNA - PRISHTINA)\s+\S+\s+([\p{L}\']+(?:\s+[\p{L}\']+){1,3}?)\s+(?=PAGES|PAG\b|PER\b|PËR\b|TATIM|TAKS|GJOB|QERDH|CERDH|KOPSHT|TETOR|NENTOR|DHJETOR|BLERJE|TRASHEG|SHFRY|ANETAR|DUPLIKAT|\d|$)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $m) && self::looksLikeName($m[1])) return [self::cleanName($m[1]), 'description'];
        }
        return [null, null];
    }

    private static function identification(string $text, ?array $uniref): array
    {
        if (preg_match('/(?:\bNR\.?\s*(?:I\s*)?ID|\bID|NR\.?\s*PERSONAL|NUMRI\s+PERSONAL|\bN\.?P\.?)\s*[:.]?\s*(\d{10})\b/u', $text, $m)) return [$m[1], 'personal_number', 'label'];
        if (preg_match('/(?:\bNUI|\bNRF|NR\.?\s*FISKAL|\bNRB)\.?\s*[:.]?\s*(\d{9})\b/u', $text, $m)) return [$m[1], 'business_number', 'label'];
        $reference = $uniref['reference'] ?? '';
        foreach (preg_match_all('/(?<![\w\/-])([12]\d{9})(?![\w\/-])/u', $text, $all) ? $all[1] : [] as $candidate) {
            // Bill numbers of the property tax (3354…) and the UNIREF body itself are not identification numbers.
            if ($reference !== '' && substr($candidate, 0, 6) === substr($reference, 0, 6)) continue;
            return [$candidate, 'personal_number', 'pattern'];
        }
        if (preg_match('/(?<![\w\/-])(81\d{7})(?![\w\/-])/u', $text, $m)) return [$m[1], 'business_number', 'pattern'];
        return [null, null, null];
    }

    private static function invoice(string $text, string $payer, ?array $uniref, ?string $group): array
    {
        if ($uniref !== null && $uniref['type'] === 'B2' && !$uniref['generic'] && ctype_digit($uniref['reference'])) return [$uniref['reference'], 'uniref'];
        if (preg_match('/(?:NR\s*\.?\s*(?:I\s*)?FAT(?:URES|URËS)?\.?|FAT(?:URA|URE|URES|URËS)?\s*(?:ME\s*)?(?:NR|NUMER|NUMËR)\.?|FAT\.?\s*NR\.?|NRFAT|NR\.?\s*SERIK|NRSERIK)\s*[:.]?\s*(\d[\d\/-]{0,24}\d|\d)/u', $text, $m)) return [$m[1], 'label'];
        if (preg_match('/FATURESMENUMER(\d{3,10})/u', $text, $m)) return [$m[1], 'label'];
        if ($group === '30') {
            if (preg_match('/^0\d{3,5}$/', trim($payer))) return [trim($payer), 'payer'];
            if (preg_match('/(?:^|[\s|])(0\d{3,5})(?=[^\d]|$)/u', $text, $m)) return [$m[1], 'pattern'];
        }
        return [null, null];
    }

    private static function property(string $text): ?string
    {
        if (preg_match('/\bP[-\s]?(\d{8})[-\s]?(\d{5})[-\s]?(\d{1,2})\b/u', $text, $m)) return 'P-'.$m[1].'-'.$m[2].'-'.$m[3];
        if (preg_match('/(?:NJESI\s+(?:PARCELE|OBJEKTI)(?:\s+APO\s+NJESI\s+(?:PARCELE|OBJEKTI))?|PARCEL(?:A|ES|ËS)?\s+NR\.?|NR\.?\s*(?:I\s*)?(?:PRON(?:ËS|ES)?|PARCEL(?:ËS|ES)?)|NGASTR(?:A|ËS|ES)\s+NR\.?)\s*[:.]?\s*(\d[\d\/-]{2,29})/u', $text, $m)) return $m[1];
        return null;
    }

    /**
     * Number of the taxpayer in the property-tax register ("NR.TATIMPAGUESIT 2191310"). $billReference is set for
     * property-tax rows: there an unlabelled 7–8 digit number starting with 1 or 2 next to the bill is that number.
     */
    private static function taxpayer(string $text, ?string $billReference): array
    {
        if (preg_match('/(?:NR\.?\s*(?:I\s*)?TATIMPAGUES(?:IT|I)?|TATIMPAGUES(?:IT|I)?)\s*[:.]?\s*(\d{4,10})\b/u', $text, $m)) return [$m[1], 'label'];
        if ($billReference !== null && preg_match_all('/(?<![\w\/.,-])([12]\d{6,7})(?![\w\/.,-])/u', $text, $all)) {
            foreach ($all[1] as $candidate) if ($billReference === '' || !str_contains($billReference, $candidate)) return [$candidate, 'pattern'];
        }
        return [null, null];
    }

    private static function plate(string $text): ?string
    {
        if (preg_match_all('/(?<![\d\w-])(0[1-7])\s*-?\s*(\d{3})\s*-?\s*([A-Z]{2})(?![\w])/u', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) if (!in_array($m[3], ['DT','NR','ID','KK','PER','IP'], true)) return $m[1].'-'.$m[2].'-'.$m[3];
        }
        return null;
    }

    private static function caseReference(string $text): ?string
    {
        if (preg_match('/(?<!\d)(\d{2})[\s-](\d{3})[\s\/](\d{2})[\s-](\d{5,7})(?:[\s\/](\d{2}))?(?!\d)/u', $text, $m)) return $m[1].'-'.$m[2].'/'.$m[3].'-'.$m[4].(isset($m[5]) && $m[5] !== '' ? '/'.$m[5] : '');
        return null;
    }

    private static function channel(string $payer, string $description): string
    {
        $text = mb_strtoupper($payer.' '.$description);
        return match (true) {
            str_contains($text, 'EKOSOVA') => 'eKosova',
            str_contains($text, 'TXN') && str_contains($text, 'PAYMENT - KOMUNA') => 'Онлајн плаќање',
            str_starts_with(mb_strtoupper($payer), 'TEB') => 'TEB',
            str_contains($text, 'POSTA E KOSOV') => 'Македонска пошта',
            str_starts_with(mb_strtoupper($description), 'PAGESE PER KK') => 'Банкарски шалтер',
            default => 'Банка',
        };
    }

    private static function looksLikeName(string $value): bool
    {
        $value = trim($value);
        if (mb_strlen($value) < 4 || mb_strlen($value) > 120) return false;
        if (preg_match('/^[\d\s\/.,-]*$/', $value) || preg_match('/^(?:-+|\d{2}|P\.?K\.?)$/', $value)) return false;
        $words = preg_split('/\s+/u', (string) preg_replace('/[^\p{L}\s\']/u', ' ', $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return (count(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 2)) >= 2 || (preg_match(self::BUSINESS, $value) === 1 && mb_strlen($words[0] ?? '') >= 3))
            && !preg_match('/^(?:PAGES|PAGESE|TAKS|TATIM|TARGA|KOMUNA|TETOR|NR\b)/iu', $value);
    }

    private static function cleanName(string $value): string { return self::clip(trim((string) preg_replace('/\s+/u', ' ', $value), " \t-,.;"), 200); }
    private static function squash(string $value): string { return trim((string) preg_replace('/\s+/u', ' ', $value)); }
    private static function clip(string $value, int $length): string { return mb_substr($value, 0, $length); }

    public static function normalizeAmount(string $value): string
    {
        $value = trim(str_replace(["\xC2\xA0", ' ', '€'], '', $value));
        if ($value === '') return '';
        if (str_contains($value, ',') && str_contains($value, '.')) $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        elseif (str_contains($value, ',')) $value = str_replace(',', '.', $value);
        if (!is_numeric($value)) return '';
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    public static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) && (float) $value > 30000 && (float) $value < 80000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor((float) $value).' days')->format('Y-m-d');
        }
        foreach (['!d.m.Y', '!Y-m-d', '!d/m/Y', '!d.m.Y H:i:s', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $year = (int) $date->format('Y');
                return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d') : '';
            }
        }
        return '';
    }
}
