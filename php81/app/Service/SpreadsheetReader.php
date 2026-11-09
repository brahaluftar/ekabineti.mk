<?php

declare(strict_types=1);

namespace EKabineti\Service;

use ZipArchive;

final class SpreadsheetReader
{
    public function rows(string $path): iterable
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xls') {
            yield from $this->legacyRows($path);
            return;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            $handle = fopen($path, 'rb');
            if ($handle === false) throw new \RuntimeException('Cannot open CSV file.');
            $first = fgets($handle);
            if ($first === false) { fclose($handle); return; }
            $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);
            // Regional Excel exports use ';' (or tab) instead of ','; pick the most frequent separator of the header row.
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts); $delimiter = (string) array_key_first($counts);
            yield str_getcsv($first, $delimiter);
            while (($row = fgetcsv($handle, null, $delimiter)) !== false) yield $row;
            fclose($handle); return;
        }
        if (!class_exists(ZipArchive::class)) throw new \RuntimeException('ZipArchive is required to read XLSX files.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Cannot open XLSX file.');
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml === false) { $zip->close(); throw new \RuntimeException('XLSX shared strings are invalid.'); }
            foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $parts = [];
                foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) $parts[] = (string) $text;
                $shared[] = implode('', $parts);
            }
        }
        $sheetXml = $zip->getFromName(self::firstSheetPath($zip));
        if ($sheetXml === false) { $zip->close(); throw new \RuntimeException('XLSX has no first worksheet.'); }
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) { $zip->close(); throw new \RuntimeException('XLSX first worksheet is invalid.'); }
        foreach ($xml->xpath('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $values = [];
            foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r']; preg_match('/^[A-Z]+/', $reference, $m); $index = self::columnIndex($m[0] ?? 'A');
                while (count($values) < $index) $values[] = '';
                $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                if ((string) $cell['t'] === 's') $value = $shared[(int) $value] ?? '';
                elseif ((string) $cell['t'] === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('./*[local-name()="is"]//*[local-name()="t"]') ?: [] as $text) $parts[] = (string) $text;
                    $value = implode('', $parts);
                }
                $values[$index] = $value;
            }
            ksort($values); yield array_values($values);
        }
        $zip->close();
    }

    /**
     * Every worksheet of an XLSX workbook in workbook order: [sheet name => [row number => [column index => value]]].
     * Row numbers are the real Excel row numbers (1-based); formula cells return their cached value.
     */
    public function sheets(string $path): array
    {
        if (!class_exists(ZipArchive::class)) throw new \RuntimeException('ZipArchive is required to read XLSX files.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Cannot open XLSX file.');
        try {
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false && ($xml = simplexml_load_string($sharedXml)) !== false) {
                foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
                    $parts = [];
                    foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) $parts[] = (string) $text;
                    $shared[] = implode('', $parts);
                }
            }
            $result = [];
            foreach (self::sheetPaths($zip) as $name => $sheetPath) {
                $sheetXml = $zip->getFromName($sheetPath);
                $xml = $sheetXml === false ? false : simplexml_load_string($sheetXml);
                if ($xml === false) { $result[$name] = []; continue; }
                $rows = [];
                foreach ($xml->xpath('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                    $values = [];
                    foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                        preg_match('/^([A-Z]+)(\d*)/', (string) $cell['r'], $m);
                        $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                        $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                        if ((string) $cell['t'] === 's') $value = $shared[(int) $value] ?? '';
                        elseif ((string) $cell['t'] === 'inlineStr') {
                            $parts = [];
                            foreach ($cell->xpath('./*[local-name()="is"]//*[local-name()="t"]') ?: [] as $text) $parts[] = (string) $text;
                            $value = implode('', $parts);
                        }
                        if ($value !== '') $values[self::columnIndex($m[1] ?? 'A')] = $value;
                    }
                    if ($values !== []) { ksort($values); $rows[(int) $row['r']] = $values; }
                }
                $result[$name] = $rows;
            }
            return $result;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,string> sheet name => worksheet path, in workbook order */
    private static function sheetPaths(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relations === false) return [];
        $book = simplexml_load_string($workbook); $rels = simplexml_load_string($relations);
        if ($book === false || $rels === false) return [];
        $targets = [];
        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) $targets[(string) $relation['Id']] = ltrim((string) $relation['Target'], '/');
        $paths = [];
        foreach ($book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheet) {
            $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
            if (!isset($targets[$id])) continue;
            $path = str_starts_with($targets[$id], 'xl/') ? $targets[$id] : 'xl/'.$targets[$id];
            if ($zip->locateName($path) !== false) $paths[(string) $sheet['name']] = $path;
        }
        return $paths;
    }

    private function legacyRows(string $path): iterable
    {
        if (class_exists('COM')) {
            $excel = new \COM('Excel.Application');
            $excel->Visible = false; $excel->DisplayAlerts = false;
            try {
                $book=$excel->Workbooks->Open($path,0,true);$sheet=$book->Worksheets(1);$used=$sheet->UsedRange;
                for($r=1;$r<=(int)$used->Rows->Count;$r++){ $values=[];for($c=1;$c<=(int)$used->Columns->Count;$c++)$values[]=(string)$sheet->Cells->Item($r,$c)->Text;yield $values; }
                $book->Close(false);
            } finally { $excel->Quit(); }
            return;
        }
        $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));
        if(in_array('shell_exec',$disabled,true)||in_array('exec',$disabled,true))throw new LegacySpreadsheetException('Серверот не дозволува конверзија на .xls датотеки. Зачувајте ја листата како .xlsx и обидете се повторно.');
        $binary=trim((string)shell_exec('command -v libreoffice 2>/dev/null || command -v soffice 2>/dev/null'));
        if($binary==='')throw new LegacySpreadsheetException('За .xls е потребен LibreOffice. Зачувајте ја листата како .xlsx и обидете се повторно.');
        $directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ek-xls-'.bin2hex(random_bytes(8));
        if(!mkdir($directory,0700,true))throw new LegacySpreadsheetException('Приватната папка за конверзија не е создадена.');
        $home=$directory.DIRECTORY_SEPARATOR.'home';$profile=$directory.DIRECTORY_SEPARATOR.'profile';mkdir($home,0700,true);
        try{$profileUri='file://'.str_replace(DIRECTORY_SEPARATOR,'/',$profile);$timeout=trim((string)shell_exec('command -v timeout 2>/dev/null'));$command=($timeout!==''?escapeshellarg($timeout).' --kill-after=10 180 ':'').'env HOME='.escapeshellarg($home).' TMPDIR='.escapeshellarg($directory).' '.escapeshellarg($binary).' -env:UserInstallation='.escapeshellarg($profileUri).' --headless --convert-to xlsx --outdir '.escapeshellarg($directory).' '.escapeshellarg($path).' 2>&1';$output=[];$code=1;exec($command,$output,$code);$converted=$directory.DIRECTORY_SEPARATOR.pathinfo($path,PATHINFO_FILENAME).'.xlsx';if($code!==0||!is_file($converted))throw new LegacySpreadsheetException('Конверзијата на .xls не успеа на серверот. Зачувајте ја листата како .xlsx и обидете се повторно. Детал: '.mb_substr(implode(' ',array_slice($output,-3)),0,300));yield from $this->rows($converted);}
        finally{self::removeTree($directory);}
    }

    private static function removeTree(string $directory): void
    {
        if(!is_dir($directory))return;$items=scandir($directory);if($items===false)return;foreach($items as$item){if($item==='.'||$item==='..')continue;$path=$directory.DIRECTORY_SEPARATOR.$item;if(is_dir($path)&&!is_link($path))self::removeTree($path);else@unlink($path);}@rmdir($directory);
    }

    /** Resolves the first worksheet in workbook order (not necessarily sheet1.xml). */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relations === false) return 'xl/worksheets/sheet1.xml';
        $book = simplexml_load_string($workbook); $rels = simplexml_load_string($relations);
        if ($book === false || $rels === false) return 'xl/worksheets/sheet1.xml';
        $sheet = $book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]')[0] ?? null;
        if ($sheet === null) return 'xl/worksheets/sheet1.xml';
        $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) {
            if ((string) $relation['Id'] !== $id) continue;
            $target = ltrim((string) $relation['Target'], '/');
            $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            return $zip->locateName($path) !== false ? $path : 'xl/worksheets/sheet1.xml';
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * First worksheet of an XLSX, streamed with XMLReader (large single-sheet exports such as the daily revenue report):
     * yields Excel row number - 1 => [column index => value, gaps filled with ''] for every non-empty row.
     */
    public function firstSheetRows(string $path): iterable
    {
        if (!class_exists(ZipArchive::class) || !class_exists(\XMLReader::class)) throw new \RuntimeException('ZipArchive and XMLReader are required to read XLSX files.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Cannot open XLSX file.');
        $sheetPath = self::firstSheetPath($zip);
        $hasShared = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();
        $source = 'zip://'.$path.'#';
        $shared = [];
        if ($hasShared) {
            $xml = new \XMLReader();
            if (!$xml->open($source.'xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) throw new \RuntimeException('XLSX shared strings are invalid.');
            $text = null;
            while ($xml->read()) {
                if ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'si') $text = '';
                elseif ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 't' && $text !== null && !$xml->isEmptyElement) $text .= $xml->readString();
                elseif ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->localName === 'si') { $shared[] = (string) $text; $text = null; }
            }
            $xml->close();
        }
        $xml = new \XMLReader();
        if (!$xml->open($source.$sheetPath, null, LIBXML_NONET | LIBXML_COMPACT)) throw new \RuntimeException('XLSX first worksheet is invalid.');
        $values = []; $rowNumber = 0; $cell = null; $type = ''; $value = '';
        while ($xml->read()) {
            if ($xml->nodeType === \XMLReader::ELEMENT) {
                switch ($xml->localName) {
                    case 'row': $values = []; $rowNumber = (int) $xml->getAttribute('r') ?: $rowNumber + 1; if ($xml->isEmptyElement) $values = null; break;
                    case 'c':
                        preg_match('/^[A-Z]+/', (string) $xml->getAttribute('r'), $m);
                        $cell = isset($m[0]) ? self::columnIndex($m[0]) : count((array) $values);
                        $type = (string) $xml->getAttribute('t'); $value = '';
                        if ($xml->isEmptyElement) $cell = null;
                        break;
                    case 'v': if ($cell !== null && !$xml->isEmptyElement) $value = $xml->readString(); break;
                    case 't': if ($cell !== null && $type === 'inlineStr' && !$xml->isEmptyElement) $value .= $xml->readString(); break;
                }
            } elseif ($xml->nodeType === \XMLReader::END_ELEMENT) {
                if ($xml->localName === 'c' && $cell !== null) {
                    $values[$cell] = $type === 's' ? ($shared[(int) $value] ?? '') : $value;
                    $cell = null;
                } elseif ($xml->localName === 'row' && $values) {
                    $row = array_fill(0, max(array_keys($values)) + 1, '');
                    foreach ($values as $index => $v) $row[$index] = $v;
                    yield $rowNumber - 1 => $row;
                }
            }
        }
        $xml->close();
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0; foreach (str_split($letters) as $letter) $index = $index * 26 + ord($letter) - 64; return $index - 1;
    }
}
