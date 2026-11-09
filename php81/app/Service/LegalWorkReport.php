<?php

declare(strict_types=1);

namespace EKabineti\Service;

use ZipArchive;

/**
 * Weekly work report of a Legal Office officer (Word). Layout:
 *   Датум: 09.10.2026 · Од: <службеник>, <звање> · Тема: Работен извештај за неделата 05 – 09 октомври
 *   НАСЛОВ НА ДЕЛОТ (пријавен број, на пр. „37 вкупно, 34 плата, 3 поништување на одлука“)
 *     - Активност, странки, П-2257/26, предмет на спорот[, датум на рочиште]
 * A document may hold several reports (each starts with "Датум:"). parse() keeps every line as written and adds the
 * activity, parties, normalised case number, subjects (themes), their domain (salary, property, damages …) and the
 * hearing date, so the report can be grouped and linked to Legal Office cases.
 */
final class LegalWorkReport
{
    public const DOMAINS = [
        'salary'=>'Плата и додатоци', 'property'=>'Имот', 'damages'=>'Надомест на штета', 'administrative'=>'Поништување на одлука',
        'measure'=>'Мерки за обезбедување', 'other'=>'Други',
    ];

    /** Клучни зборови за предметот на спорот (мали букви) => [шифра на темата, назив, област]. Најдолгото совпаѓање има предност. */
    public const THEMES = [
        'дневниц'=>['meals', 'Дневници', 'salary'], 'инфлациј'=>['inflation', 'Инфлација', 'salary'], 'квалификациј'=>['qualification', 'Квалификација', 'salary'],
        'дежурств'=>['on_call', 'Дежурство', 'salary'], 'ковид'=>['covid', 'Ковид додаток', 'salary'], 'викенд'=>['weekend', 'Работа за викенд', 'salary'],
        'годишен одмор'=>['annual_leave', 'Годишен одмор', 'salary'],
        'дополнителни часови'=>['overtime', 'Прекувремени часови', 'salary'], 'прекувремен'=>['overtime', 'Прекувремени часови', 'salary'], 'замена на часови'=>['overtime', 'Прекувремени часови', 'salary'],
        'додаток на плата'=>['salary_supplement', 'Додаток на плата', 'salary'], 'дополнителна плата'=>['salary_supplement', 'Додаток на плата', 'salary'],
        'надомест на плата'=>['salary_compensation', 'Надомест на плата', 'salary'], 'плата'=>['salary_compensation', 'Надомест на плата', 'salary'],
        'штрајк'=>['strike', 'Штрајк', 'salary'], 'превоз'=>['transport', 'Превоз', 'salary'], 'лиценц'=>['licensing', 'Лиценцирање', 'salary'],
        'јубилеј'=>['jubilee', 'Јубилејна награда', 'salary'],
        'потврда за сопственост'=>['ownership', 'Потврда за сопственост', 'property'], 'враќање на имот'=>['property_return', 'Враќање на имот', 'property'],
        'ослободување на имот'=>['property_release', 'Ослободување на имот', 'property'], 'смеќавање посед'=>['possession', 'Смеќавање посед', 'property'],
        'експропријациј'=>['expropriation', 'Надомест за експропријација', 'property'],
        'надомест на штета'=>['damages', 'Надомест на штета', 'damages'], 'изгубена добивка'=>['lost_profit', 'Изгубена добивка', 'damages'],
        'поништување на одлука'=>['annulment', 'Поништување на одлука', 'administrative'], 'поништување на решение'=>['annulment', 'Поништување на одлука', 'administrative'],
        'мерка за обезбедување'=>['security_measure', 'Мерка за обезбедување', 'measure'],
    ];

    /** Наслови на деловите (големи букви) => [шифра, назив, процесна фаза на предмет создаден од нив]. */
    public const SECTIONS = [
        'ОДГОВОР НА ТУЖБА'=>['claim_response', 'Одговор на тужба', 'first_instance'],
        'ПОДНЕСОК'=>['submission', 'Поднесок', 'first_instance'], 'ПОДНЕСОЦИ'=>['submission', 'Поднесок', 'first_instance'],
        'ОДГОВОР НА ПРЕДЛОГ ЗА МЕРКА'=>['measure_response', 'Одговор на предлог за мерка', 'first_instance'],
        'ЗАСТАПУВАЊА'=>['representation', 'Застапувања на рочишта', 'first_instance'], 'ЗАСТАПУВАЊЕ'=>['representation', 'Застапувања на рочишта', 'first_instance'],
        'ЖАЛБА'=>['appeal', 'Жалби', 'appeal'], 'ЖАЛБИ'=>['appeal', 'Жалби', 'appeal'], 'РЕВИЗИЈА'=>['revision', 'Ревизија', 'revision'],
        'КАНЦЕЛАРИСКА РАБОТА'=>['office_work', 'Канцелариска работа', null],
    ];

    private const MONTHS = ['јануари'=>1, 'февруари'=>2, 'март'=>3, 'април'=>4, 'мај'=>5, 'јуни'=>6, 'јули'=>7, 'август'=>8, 'септември'=>9, 'октомври'=>10, 'ноември'=>11, 'декември'=>12];
    private const CASE_REF = '/(?<![\p{L}\d])(?|(K\.?\s?Dh\.?\s?Sh\.?\s?(?:I{1,3}\.?)?|AKP|AC|AP|CA|C|A|E|P|ARJ|Rev)\s*\.?\s*nr\s*\.?|(РЕВ2|РЕВ|ПОУ|ПЛ1|ГЖН|ГЖ|КЖ|РОЖ|РО|ИЗВ|П|К)\s*\.?\s*(?:бр\s*\.?|-)?)\s*(\d{1,6})\s*[\/-]\s*(\d{2,4})\b/u';

    /** Columns of the Excel template (sheet "Активности"), in order: key => header. */
    public const TEMPLATE_COLUMNS = [
        'report_date'=>'Датум на извештајот', 'officer_name'=>'Службеник', 'officer_title'=>'Наслов на службеникот', 'period_start'=>'Период од', 'period_end'=>'Период до',
        'section'=>'Вид на работа', 'activity'=>'Активност', 'parties'=>'Странки', 'case_reference'=>'Бр. на предмет', 'subject'=>'Предмет на спорот', 'hearing_date'=>'Датум на рочиштето',
    ];
    public const TEMPLATE_SHEET = 'Активности';

    /** Section labels offered in the template (one per section code). */
    public static function sectionLabels(): array
    {
        $labels = [];
        foreach (self::SECTIONS as [$code, $label]) $labels[$code] = $label;
        return $labels;
    }

    /** Subject labels offered in the template (one per theme). */
    public static function themeLabels(): array
    {
        $labels = [];
        foreach (self::THEMES as [$code, $label]) $labels[$code] = $label;
        return $labels;
    }

    /**
     * Reports from the Excel template: one row per activity; rows with the same officer and period form one report.
     * Rows without officer, period or activity are reported and skipped.
     *
     * @param array<int,array<int,string>> $rows Excel row number => [column index => value] (SpreadsheetReader::sheets)
     */
    public static function fromSheet(array $rows): array
    {
        $map = null; $reports = []; $skipped = [];
        $sections = [];
        foreach (self::SECTIONS as [$code, $label, $phase]) $sections[self::key($label)] = [$code, $label, $phase];
        foreach (self::SECTIONS as $title => [$code, $label, $phase]) $sections[$title] = [$code, $label, $phase];
        foreach ($rows as $number => $cells) {
            $cells = array_map(static fn($v): string => trim((string) $v), (array) $cells);
            if ($map === null) {
                $headers = array_flip(array_map(static fn(string $h): string => self::key($h), self::TEMPLATE_COLUMNS));
                $found = [];
                foreach ($cells as $index => $value) if (isset($headers[self::key($value)])) $found[$headers[self::key($value)]] = $index;
                if (isset($found['officer_name'], $found['activity'], $found['section'])) $map = $found;
                continue;
            }
            $get = static fn(string $key): string => isset($map[$key]) ? (string) ($cells[$map[$key]] ?? '') : '';
            if (implode('', array_map($get, array_keys(self::TEMPLATE_COLUMNS))) === '') continue;
            $officer = $get('officer_name'); $activity = $get('activity');
            $start = self::cellDate($get('period_start')); $end = self::cellDate($get('period_end')) ?? $start; $start ??= $end;
            if ($officer === '' || $activity === '' || $start === null) { $skipped[] = 'Редот '.$number.' е прескокнат: недостасува '.implode(', ', array_filter([$officer === '' ? 'службеник' : null, $activity === '' ? 'активност' : null, $start === null ? 'период' : null])).'.'; continue; }
            if ($end < $start) [$start, $end] = [$end, $start];
            $reportDate = self::cellDate($get('report_date')) ?? $end;
            $key = mb_strtolower($officer).'|'.$start.'|'.$end;
            $reports[$key] ??= ['report_date'=>$reportDate, 'officer_name'=>mb_substr($officer, 0, 200), 'officer_title'=>$get('officer_title') !== '' ? mb_substr($get('officer_title'), 0, 200) : null,
                'subject'=>'Работен извештај (Excel) '.date('d.m.Y', strtotime($start)).' – '.date('d.m.Y', strtotime($end)), 'period_start'=>$start, 'period_end'=>$end, 'sections'=>[], 'items'=>[], 'warnings'=>[]];
            $sectionText = $get('section');
            $section = $sections[self::key($sectionText)] ?? ['other_'.substr(md5(self::key($sectionText !== '' ? $sectionText : 'Други')), 0, 8), $sectionText !== '' ? $sectionText : 'Други', null];
            if (!in_array($section[0], array_column($reports[$key]['sections'], 'code'), true)) $reports[$key]['sections'][] = ['code'=>$section[0], 'title'=>mb_strtoupper($section[1]), 'label'=>$section[1], 'phase'=>$section[2], 'declared_text'=>'', 'declared'=>null];
            $hearing = self::cellDate($get('hearing_date'));
            $text = implode(', ', array_filter([$activity, $get('parties'), $get('case_reference'), $get('subject'), $hearing !== null ? date('d.m.Y', strtotime($hearing)) : '']));
            $item = self::item($text, $reportDate);
            // Explicit columns win over what is read from the composed line.
            $parties = array_values(array_filter(array_map('trim', preg_split('/[;\n]+/u', $get('parties')) ?: [])));
            $item['activity'] = mb_substr($activity, 0, 250);
            $item['parties'] = mb_substr(implode('; ', $parties), 0, 500);
            $item['case_reference'] = $get('case_reference') !== '' ? (self::caseKey($get('case_reference')) ?? mb_substr($get('case_reference'), 0, 60)) : null;
            $item['subject'] = mb_substr($get('subject'), 0, 500);
            $item['hearing_date'] = $hearing;
            $item['section_code'] = $section[0]; $item['section_label'] = $section[1]; $item['phase'] = $section[2];
            $item['sort_order'] = count($reports[$key]['items']) + 1;
            $reports[$key]['items'][] = $item;
        }
        $reports = array_values($reports);
        if ($map === null) return [['report_date'=>null, 'officer_name'=>null, 'officer_title'=>null, 'subject'=>null, 'period_start'=>null, 'period_end'=>null, 'sections'=>[], 'items'=>[], 'warnings'=>['Листот ги нема насловите од образецот (Службеник, Вид на работа, Активност …). Преземете го образецот од страницата.']]];
        if ($skipped !== [] && $reports !== []) $reports[0]['warnings'] = array_merge($reports[0]['warnings'], $skipped);
        return $reports;
    }

    /** 09.10.2026, 2026-10-09, 9/10/2026 or an Excel date number. */
    private static function cellDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('/^\d{5}(?:\.\d+)?$/', $value)) return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) $value.' days')->format('Y-m-d');
        foreach (['!d.m.Y', '!Y-m-d', '!d/m/Y', '!d.m.y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date === false) continue;
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) return $date->format('Y-m-d');
        }
        return null;
    }

    /** Paragraphs of a .docx: [text, is list item, is bold]. */
    public static function docxParagraphs(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Документот не може да се отвори (оштетен DOCX).');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) throw new \RuntimeException('Документот нема Word содржина.');
        $paragraphs = [];
        foreach (preg_match_all('/<w:p[ >].*?<\/w:p>/s', $xml, $m) ? $m[0] : [] as $p) {
            $text = '';
            foreach (preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>|<w:tab\/>/s', $p, $t, PREG_SET_ORDER) ? $t : [] as $part) $text .= isset($part[1]) ? $part[1] : ' ';
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8')));
            if ($text === '') continue;
            $paragraphs[] = [$text, str_contains($p, '<w:numPr>'), (bool) preg_match('/<w:b\/>|<w:b w:val="(?:1|true)"\/>/', $p)];
        }
        return $paragraphs;
    }

    /**
     * .docx is read directly. .doc (Word 97–2003) is read by WordBinaryReader, in PHP; only when that fails (e.g. a
     * document saved with "fast save") is it converted with LibreOffice (stopped after 2 minutes).
     */
    public static function paragraphs(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'docx') return self::docxParagraphs($path);
        try {
            return array_map(static fn(string $line): array => [$line, false, false], WordBinaryReader::paragraphs($path));
        } catch (\RuntimeException $nativeError) {
            // Fall through to LibreOffice; if that is missing too, the user is told why the document could not be read.
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true) || in_array('shell_exec', $disabled, true)) throw new LegacySpreadsheetException($nativeError->getMessage().' Зачувајте го извештајот како .docx (Word: Зачувај како → Word Document) и обидете се повторно.');
        $binary = trim((string) shell_exec('command -v libreoffice 2>/dev/null || command -v soffice 2>/dev/null'));
        if ($binary === '') throw new LegacySpreadsheetException($nativeError->getMessage().' Зачувајте го извештајот како .docx и обидете се повторно.');
        $directory = sys_get_temp_dir().'/ek-doc-'.bin2hex(random_bytes(8));
        mkdir($directory.'/home', 0700, true);
        try {
            $timeout = trim((string) shell_exec('command -v timeout 2>/dev/null'));
            $command = ($timeout !== '' ? escapeshellarg($timeout).' --kill-after=10 120 ' : '').'env HOME='.escapeshellarg($directory.'/home').' TMPDIR='.escapeshellarg($directory).' '.escapeshellarg($binary)
                .' -env:UserInstallation='.escapeshellarg('file://'.$directory.'/profile').' --headless --convert-to docx --outdir '.escapeshellarg($directory).' '.escapeshellarg($path).' 2>&1';
            exec($command, $output, $code);
            $converted = $directory.'/'.pathinfo($path, PATHINFO_FILENAME).'.docx';
            if ($code !== 0 || !is_file($converted)) throw new LegacySpreadsheetException($nativeError->getMessage().' Конверзијата со LibreOffice исто така не успеа. Зачувајте го извештајот како .docx (Word: Зачувај како → Word Document) и обидете се повторно.');
            return self::docxParagraphs($converted);
        } finally {
            foreach (glob($directory.'/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $file) if (is_file($file)) @unlink($file);
            exec('rm -rf '.escapeshellarg($directory));
        }
    }

    /** @return list<array> reports: header fields, sections (declared counts) and items. */
    public static function parse(array $paragraphs): array
    {
        $reports = []; $report = null; $section = null; $expect = null;
        foreach ($paragraphs as [$text, $isList, $isBold]) {
            $key = self::key($text);
            if (preg_match('/^(?:DATE\/?A|ДАТУМ)\s*:?\s*(\d{1,2}\.\d{1,2}\.\d{4})?/u', $key, $m)) {
                if ($report !== null) $reports[] = $report;
                $report = ['report_date'=>isset($m[1]) ? self::date($m[1]) : null, 'officer_name'=>null, 'officer_title'=>null, 'subject'=>null, 'period_start'=>null, 'period_end'=>null, 'sections'=>[], 'items'=>[], 'warnings'=>[]];
                $section = null; $expect = isset($m[1]) ? null : 'date';
                continue;
            }
            if ($report === null) continue;
            if (preg_match('/^(?:NGA\s*\/\s*OD|ОД\s*:)/u', $key)) { $rest = trim((string) preg_replace('/^.*?(?:From|Од)\s*:\s*/iu', '', $text)); if ($rest !== '' && !preg_match('/(?:From|Од)\s*:?$/iu', $text)) self::officer($report, $rest); else $expect = 'officer'; continue; }
            if (preg_match('/^(?:TEMA\s*\/\s*SUBJEKAT|ТЕМА\s*:)/u', $key)) { $rest = trim((string) preg_replace('/^.*?(?:Subject|Тема)\s*:\s*/iu', '', $text)); if ($rest !== '' && !preg_match('/(?:Subject|Тема)\s*:?$/iu', $text)) self::subject($report, $rest); else $expect = 'subject'; continue; }
            if ($expect !== null) {
                if ($expect === 'date' && preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})$/', $text, $m)) $report['report_date'] = self::date($m[1]);
                elseif ($expect === 'officer') self::officer($report, $text);
                elseif ($expect === 'subject') self::subject($report, $text);
                $expect = null;
                continue;
            }
            if (!$isList && preg_match('/^([\p{Lu}\s]{4,})\s*(?:\((.*)\))?\s*$/u', $text, $m) && mb_strtoupper($m[1]) === $m[1]) {
                $title = trim($m[1]);
                $known = self::SECTIONS[self::key($title)] ?? null;
                $section = ['code'=>$known[0] ?? 'other_'.substr(md5(self::key($title)), 0, 8), 'title'=>$title, 'label'=>$known[1] ?? self::titleCase($title), 'phase'=>$known[2] ?? null, 'declared_text'=>trim((string) ($m[2] ?? '')), 'declared'=>self::declared((string) ($m[2] ?? ''))];
                $report['sections'][] = $section;
                continue;
            }
            if ($section === null) continue;
            $item = self::item($text, $report['report_date']);
            $item['section_code'] = $section['code'];
            $item['section_label'] = $section['label'];
            $item['phase'] = $section['phase'];
            $item['sort_order'] = count($report['items']) + 1;
            $report['items'][] = $item;
        }
        if ($report !== null) $reports[] = $report;
        foreach ($reports as &$r) {
            if ($r['report_date'] === null) $r['warnings'][] = 'Датумот на извештајот не е пронајден.';
            if ($r['officer_name'] === null) $r['warnings'][] = 'Службеникот (Од/From) не е пронајден.';
            if ($r['period_start'] === null && $r['report_date'] !== null) { $r['period_start'] = $r['period_end'] = $r['report_date']; $r['warnings'][] = 'Периодот не е прочитан од темата; се користи датумот на извештајот.'; }
            foreach ($r['sections'] as $s) {
                $found = count(array_filter($r['items'], static fn(array $i): bool => $i['section_code'] === $s['code']));
                if ($s['declared'] !== null && $s['declared'] !== $found) $r['warnings'][] = $s['label'].': во насловот пишува '.$s['declared'].', прочитани се '.$found.' rreshta.';
            }
        }
        return $reports;
    }

    /** One line: activity, parties, normalised case number, themes and domain, hearing date. */
    public static function item(string $text, ?string $reportDate): array
    {
        $line = trim($text, " \t;.");
        $case = null; $prefix = null;
        if (preg_match(self::CASE_REF, $line, $m)) {
            $prefix = self::casePrefix($m[1]);
            $case = self::caseRef($prefix, (int) $m[2], $m[3]);
        }
        $hearing = null;
        if (preg_match_all('/(?<![\d\/.-])(\d{1,2})\.(\d{1,2})\.(\d{4}|\d{2})(?![\d\/-])/', $line, $all, PREG_SET_ORDER)) {
            $last = end($all);
            $hearing = self::date($last[1].'.'.$last[2].'.'.(strlen($last[3]) === 2 ? '20'.$last[3] : $last[3]));
        }
        $segments = array_values(array_filter(array_map('trim', explode(',', $line)), static fn(string $s): bool => $s !== ''));
        $activity = array_shift($segments) ?? $line;
        $parties = []; $subjects = []; $themes = []; $claimants = false;
        foreach ($segments as $segment) {
            if (preg_match(self::CASE_REF, $segment) || preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $segment)) continue;
            $segment = trim((string) preg_replace('/\s*\d{1,2}\.\d{1,2}\.\d{2,4}\s*$/', '', $segment));
            if ($segment === '') continue;
            $found = self::themes($segment);
            if ($found !== []) { $subjects[] = $segment; foreach ($found as $code => $theme) $themes[$code] = $theme; continue; }
            // "me paditës Xhelal Sopi, Naim Sopi dhe …", "sipas paditësit Ismet Rexhepi"
            if (preg_match('/^(?:me|sipas|по|според)\s+(?:padit|тужител)\p{L}*\s+(.+)$/iu', $segment, $pm)) { $claimants = true; $segment = $pm[1]; }
            if ($claimants ?? false) { foreach (preg_split('/\s+(?:dhe|и)\s+/u', $segment) ?: [] as $name) $parties[] = trim(str_replace(['"', '“', '”'], '', $name)); continue; }
            if (self::looksLikeParty($segment) && $subjects === []) { $parties[] = trim(str_replace(['"', '“', '”'], '', $segment)); continue; }
            $subjects[] = $segment;
        }
        // "Parashtrese per dergimin e provave Ismet Rexhepi": the party is the trailing name of a one-part line.
        if ($parties === [] && $segments === [] && preg_match('/^(.*?\s(\S+))\s+((?:\p{Lu}[\p{Ll}\']+\s+){1,3}\p{Lu}[\p{Ll}\']+)$/u', $activity, $m) && !self::themes($m[3]) && !in_array(mb_strtolower($m[2]), ['i', 'e', 'të', 'së', 'nga'], true)) { $activity = trim($m[1]); $parties[] = $m[3]; }
        foreach (self::themes($activity) as $code => $theme) $themes[$code] = $theme;
        $domains = array_count_values(array_column($themes, 2));
        arsort($domains);
        $domain = $domains === [] ? 'other' : (string) array_key_first($domains);
        return [
            'item_text'=>mb_substr($text, 0, 2000), 'activity'=>mb_substr($activity, 0, 250), 'parties'=>mb_substr(implode('; ', $parties), 0, 500),
            'case_reference'=>$case, 'case_prefix'=>$prefix, 'subject'=>mb_substr(implode(', ', $subjects), 0, 500),
            'themes'=>array_values(array_map(static fn(array $t): string => $t[1], $themes)), 'theme_codes'=>array_keys($themes), 'domain'=>$domain, 'hearing_date'=>$hearing,
        ];
    }

    /** Case numbers written differently ("C.nr.6562-25", "C nr 6562/2025") compare equal. */
    public static function caseKey(?string $reference): ?string
    {
        if ($reference === null || !preg_match(self::CASE_REF, $reference, $m)) return null;
        return self::caseRef(self::casePrefix($m[1]), (int) $m[2], $m[3]);
    }

    /** Македонски судски броеви: П-9001/26; старите латинични: C.nr.9001/26. */
    private static function caseRef(string $prefix, int $number, string $year): string
    {
        return preg_match('/\p{Cyrillic}/u', $prefix) ? $prefix.'-'.$number.'/'.substr($year, -2) : $prefix.'.nr.'.$number.'/'.substr($year, -2);
    }

    private static function casePrefix(string $raw): string
    {
        $compact = mb_strtoupper((string) preg_replace('/[\s.]+/u', '', $raw));
        if (str_starts_with($compact, 'KDHSH')) return 'K.Dh.Sh'.(strlen($compact) > 5 ? '.'.substr($compact, 5) : '');
        return $compact === 'REV' ? 'Rev' : $compact;
    }

    private static function themes(string $text): array
    {
        $key = mb_strtolower(self::fold($text));
        $found = [];
        $keywords = array_keys(self::THEMES);
        usort($keywords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($keywords as $keyword) {
            if (!str_contains($key, $keyword)) continue;
            [$code, $label, $domain] = self::THEMES[$keyword];
            if ($keyword === 'плата' && $found !== []) continue;
            $found[$code] = [$code, $label, $domain];
            $key = str_replace($keyword, ' ', $key);
        }
        return $found;
    }

    private static function looksLikeParty(string $text): bool
    {
        if (preg_match('/(?<!\p{L})(ДООЕЛ|ДОО|АД|и др\.?|Правобранител\p{L}*|Друштво)(?!\p{L})/iu', $text)) return true;
        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) > 5) return false;
        foreach ($words as $word) if (!preg_match('/^[\p{Lu}"“]/u', $word)) return false;
        return true;
    }

    private static function officer(array &$report, string $text): void
    {
        $parts = array_map('trim', explode(',', $text, 2));
        $report['officer_name'] = mb_substr($parts[0], 0, 200);
        $report['officer_title'] = isset($parts[1]) ? mb_substr($parts[1], 0, 200) : null;
    }

    /** „Работен извештај за неделата 05 – 09 октомври“ (годината од датумот на извештајот), „… 28.09 – 02.10.2026“, „… октомври 2026“. */
    private static function subject(array &$report, string $text): void
    {
        $report['subject'] = mb_substr($text, 0, 300);
        $year = (int) substr((string) ($report['report_date'] ?? date('Y-m-d')), 0, 4);
        $folded = mb_strtolower(self::fold($text));
        if (preg_match('/(\d{1,2})(?:\.(\d{1,2}))?\.?\s*[-–]\s*(\d{1,2})(?:\.(\d{1,2}))?\.?\s*(?:\.(\d{4}))?\s*(\p{Ll}+)?\s*(\d{4})?/u', $folded, $m)) {
            $endMonth = isset($m[6]) && isset(self::MONTHS[$m[6]]) ? self::MONTHS[$m[6]] : (int) ($m[4] ?? 0);
            $startMonth = (int) ($m[2] ?? 0) ?: $endMonth;
            $y = (int) (($m[7] ?? '') ?: (($m[5] ?? '') ?: $year));
            if ($endMonth >= 1 && $endMonth <= 12 && checkdate($startMonth, (int) $m[1], $y) && checkdate($endMonth, (int) $m[3], $y)) {
                $report['period_start'] = sprintf('%04d-%02d-%02d', $startMonth > $endMonth ? $y - 1 : $y, $startMonth, (int) $m[1]);
                $report['period_end'] = sprintf('%04d-%02d-%02d', $y, $endMonth, (int) $m[3]);
                return;
            }
        }
        foreach (self::MONTHS as $name => $month) {
            if (preg_match('/(?<!\p{L})'.$name.'(?!\p{L})\s*(\d{4})?/u', $folded, $m)) {
                $y = (int) (($m[1] ?? '') ?: $year);
                $report['period_start'] = sprintf('%04d-%02d-01', $y, $month);
                $report['period_end'] = date('Y-m-t', strtotime($report['period_start']));
                return;
            }
        }
    }

    /** „37 вкупно, 34 плата, 3 поништување“ => 37; „6 барања, 2 решенија …, 1 …“ => 9; „2“ => 2. */
    private static function declared(string $text): ?int
    {
        if (preg_match('/(\d+)\s*(?:gjithsej|вкупно)/u', $text, $m)) return (int) $m[1];
        if (!preg_match_all('/\d+/', $text, $all)) return null;
        return array_sum(array_map('intval', $all[0]));
    }

    private static function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        return $date ? $date->format('Y-m-d') : null;
    }

    private static function fold(string $text): string { return strtr($text, ['ë'=>'e', 'Ë'=>'E', 'ç'=>'c', 'Ç'=>'C']); }
    private static function key(string $text): string { return trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper(self::fold($text)))); }
    private static function titleCase(string $text): string { return mb_convert_case(mb_strtolower($text), MB_CASE_TITLE); }
}
