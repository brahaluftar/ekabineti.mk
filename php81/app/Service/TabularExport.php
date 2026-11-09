<?php

declare(strict_types=1);

namespace EKabineti\Service;

final class TabularExport
{
    public function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$this->safeFilename($filename, 'csv').'"');
        header('Cache-Control: no-store, private');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_values($headers));
        foreach ($rows as $row) {
            fputcsv($output, array_map([self::class, 'csvSafe'], $this->orderedRow($row, array_keys($headers))));
        }
        fclose($output);
        exit;
    }

    /**
     * @param array<int,array{name:string,headers:array<string,string>,rows:array<int,array<string,mixed>>}> $sheets
     */
    public function xlsx(string $filename, array $sheets): never
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is required for Excel exports.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'ekabineti-xlsx-');
        if ($temp === false) {
            throw new \RuntimeException('Unable to create the temporary Excel file.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            throw new \RuntimeException('Unable to create the Excel archive.');
        }

        $sheets = array_values($sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->packageRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties($sheets));
        $zip->addFromString('docProps/core.xml', $this->coreProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet['headers'], $sheet['rows'], $sheet));
        }
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$this->safeFilename($filename, 'xlsx').'"');
        header('Content-Length: '.filesize($temp));
        header('Cache-Control: no-store, private');
        readfile($temp);
        @unlink($temp);
        exit;
    }

    /**
     * Optional sheet keys: 'widths' => [column key => width], 'lists' => [column key => "Sheet!$A$2:$A$20"] (drop-down
     * list for rows 2..'list_rows', default 1000), 'filter' => false (no autofilter).
     */
    private function worksheet(array $headers, array $rows, array $options = []): string
    {
        $keys = array_keys($headers);
        $data = [array_values($headers)];
        foreach ($rows as $row) {
            $data[] = $this->orderedRow($row, $keys);
        }

        $xmlRows = '';
        foreach ($data as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                $style = $rowIndex === 0 ? 1 : ($rowIndex % 2 === 0 ? 2 : 0);
                if ($rowIndex > 0 && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                    $cells .= '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.$value.'</v></c>';
                } else {
                    $text = $this->xml($value === null ? '' : (string) $value);
                    $cells .= '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
                }
            }
            $xmlRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        $lastColumn = $this->columnName(max(1, count($headers)));
        $lastRow = max(1, count($data));
        $columns = '';
        $widths = (array) ($options['widths'] ?? []);
        for ($index = 1; $index <= count($headers); $index++) {
            $width = (float) ($widths[$keys[$index - 1]] ?? ($index === 1 ? 16 : 24));
            $columns .= '<col min="'.$index.'" max="'.$index.'" width="'.$width.'" customWidth="1"/>';
        }
        $validations = '';
        $listRows = (int) ($options['list_rows'] ?? 1000);
        foreach ((array) ($options['lists'] ?? []) as $key => $range) {
            $position = array_search($key, $keys, true);
            if ($position === false) continue;
            $column = $this->columnName($position + 1);
            $validations .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorStyle="warning" sqref="'.$column.'2:'.$column.$listRows.'"><formula1>'.$this->xml((string) $range).'</formula1></dataValidation>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$columns.'</cols><sheetData>'.$xmlRows.'</sheetData>'
            .(($options['filter'] ?? true) ? '<autoFilter ref="A1:'.$lastColumn.$lastRow.'"/>' : '')
            .($validations !== '' ? '<dataValidations count="'.substr_count($validations, '<dataValidation ').'">'.$validations.'</dataValidations>' : '')
            .'<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'</worksheet>';
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$overrides.'</Types>';
    }

    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbook(array $sheets): string
    {
        $items = '';
        foreach ($sheets as $index => $sheet) {
            $name = mb_substr(preg_replace('~[\\\\/?:*\[\]]~u', '-', (string) $sheet['name']) ?: 'Sheet', 0, 31);
            $items .= '<sheet name="'.$this->xml($name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews><sheets>'.$items.'</sheets></workbook>';
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $items = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $items .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }
        $items .= '<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$items.'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF12304A"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7F5F1"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border/><border><left style="thin"><color rgb="FFD9E1E8"/></left><right style="thin"><color rgb="FFD9E1E8"/></right><top style="thin"><color rgb="FFD9E1E8"/></top><bottom style="thin"><color rgb="FFD9E1E8"/></bottom></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function appProperties(array $sheets): string
    {
        $titles = '';
        foreach ($sheets as $sheet) {
            $titles .= '<vt:lpstr>'.$this->xml((string) $sheet['name']).'</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>e-Kabineti</Application><TitlesOfParts><vt:vector size="'.count($sheets).'" baseType="lpstr">'.$titles.'</vt:vector></TitlesOfParts></Properties>';
    }

    private function coreProperties(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>e-Kabineti</dc:creator><dc:title>e-Kabineti Export</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified></cp:coreProperties>';
    }

    /** Neutralises spreadsheet formulas (=, +, -, @, tab, CR) in text cells to prevent CSV injection; numbers stay numeric. */
    public static function csvSafe(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || is_numeric($value)) return $value;
        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function orderedRow(array $row, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[] = $row[$key] ?? null;
        }
        return $values;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function safeFilename(string $filename, string $extension): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export';
        $base = trim($base, '.-');
        return str_ends_with(strtolower($base), '.'.$extension) ? $base : $base.'.'.$extension;
    }
}
