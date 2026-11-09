<?php

declare(strict_types=1);

namespace EKabineti\Service;

/**
 * Македонска кирилица во споредбите и препознавањето на заглавија.
 *
 * Нормализаторите во увозите (буџет, FreeBalance, извршувања) ги сведуваат текстовите на латинични букви и бројки.
 * Без транслитерација, секој кирилски текст би станал празен клуч, па различни називи би се совпаднале.
 * fold() го транслитерира текстот според македонската латиница; headerFold() дополнително ги сведува познатите
 * македонски заглавија на каноничките клучеви што ги очекуваат парсерите (на пр. „Шифра на проектот“ → kodi i projektit).
 */
final class Cyrillic
{
    private const LATIN = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','ѓ'=>'gj','е'=>'e','ж'=>'zh','з'=>'z','ѕ'=>'dz','и'=>'i','ј'=>'j',
        'к'=>'k','л'=>'l','љ'=>'lj','м'=>'m','н'=>'n','њ'=>'nj','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','ќ'=>'kj',
        'у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','џ'=>'dj','ш'=>'sh','ѐ'=>'e','ѝ'=>'i',
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Ѓ'=>'GJ','Е'=>'E','Ж'=>'ZH','З'=>'Z','Ѕ'=>'DZ','И'=>'I','Ј'=>'J',
        'К'=>'K','Л'=>'L','Љ'=>'LJ','М'=>'M','Н'=>'N','Њ'=>'NJ','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','Ќ'=>'KJ',
        'У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C','Ч'=>'CH','Џ'=>'DJ','Ш'=>'SH','Ѐ'=>'E','Ѝ'=>'I',
        'ë'=>'e','ç'=>'c','Ë'=>'E','Ç'=>'C',
    ];

    /** Македонски заглавија (мали букви) → канонски клучеви на парсерите. Подолгите фрази се проверуваат први. */
    private const HEADERS = [
        'шифра на проектот'=>'kodi i projektit', 'шифра на проект'=>'kodi i projektit', 'преземени обврски'=>'zotimet',
        'распределен износ'=>'shuma e alokuar', 'распределено'=>'shuma e alokuar', 'расположиво салдо'=>'bilanci',
        'нераспределени средства'=>'fondet jo e obliguar', 'реализирано'=>'aktuali', 'капитални'=>'kapitalet',
        'остаток'=>'mbetja', 'буџет'=>'buxheti', 'сектор'=>'drejtoria', 'вкупно'=>'totali', 'опис'=>'pershkrimi',
        'износ'=>'shuma', 'распределба'=>'alokim', 'обврски'=>'obligimet', 'обврска'=>'zotim', 'салдо'=>'bilanci',
        'шифра'=>'kodi', 'датум'=>'data', 'уплатувач'=>'paguesi', 'корисник'=>'perfituesi', 'група'=>'grupi',
        // Листа на јавни набавки
        'шифра на договорот'=>'kodi kontrate', 'наслов на договорот'=>'titulli kontrate', 'вид на договорот'=>'llojin e kontrate',
        'договорна страна'=>'pala kontraktuese', 'менаџери'=>'managers', 'надзорници'=>'supervisors', 'шифра на набавката'=>'kodi i prokurimit',
        'назив на набавката'=>'emertimi i prokurimit', 'валута'=>'valuta', 'статус'=>'statusi', 'економски шифри'=>'kodiekonomik',
        'шифри на проектите'=>'kodiprojektit', 'времетраење (месеци)'=>'kohezgjatja muaj', 'реализирано (ден.)'=>'realizuar',
        'платено (ден.)'=>'paguar', 'извршено (ден.)'=>'permbaruar', 'вкупно платено (ден.)'=>'totalpaguar',
    ];

    public static function fold(string $value): string
    {
        return strtr($value, self::LATIN);
    }

    public static function headerFold(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        static $headers;
        if ($headers === null) { $headers = self::HEADERS; uksort($headers, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a)); }
        foreach ($headers as $mk => $key) {
            if ($lower === $mk || str_starts_with($lower, $mk.' ') || str_starts_with($lower, $mk.'_') || str_starts_with($lower, $mk.'(')) {
                $lower = $key.substr($lower, strlen($mk));
                break;
            }
        }
        return self::fold($lower);
    }
}
