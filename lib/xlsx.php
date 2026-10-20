<?php
declare(strict_types=1);

require_once __DIR__ . '/zip.php';

/**
 * Генерация .xlsx (Office Open XML) на чистом PHP.
 * Значения записываются как inlineStr/числа, поэтому sharedStrings не нужен.
 */

/** Буквенное имя колонки: 0 -> A, 25 -> Z, 26 -> AA. */
function xlsxCol(int $i): string {
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** Экранирование для XML-текста. */
function xlsxEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Имя листа: до 31 символа, без []:*?/\ и краевых апострофов. */
function xlsxSheetName(string $name): string {
    $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
    $name = trim($name, "' \t");
    if ($name === '') $name = 'Лист';
    return mb_substr($name, 0, 31, 'UTF-8');
}

function xlsxSheetXml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';
    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $val) {
            $ref = xlsxCol($c) . ($r + 1);
            if ($val === null || $val === '') continue;
            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . xlsxEsc((string)$val) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

/**
 * @param array $sheets Список листов: [['name' => 'Товары', 'rows' => [[...],[...]]], ...]
 * @return string Бинарный .xlsx
 */
function xlsxBuild(array $sheets): string {
    if (!$sheets) $sheets = [['name' => 'Лист', 'rows' => []]];

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

    $parts = [];
    foreach ($sheets as $i => $sheet) {
        $n = $i + 1;
        $rid = 'rId' . $n;
        $workbook .= '<sheet name="' . xlsxEsc(xlsxSheetName((string)($sheet['name'] ?? ('Лист' . $n)))) . '"'
            . ' sheetId="' . $n . '" r:id="' . $rid . '"/>';
        $wbRels .= '<Relationship Id="' . $rid . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $n . '.xml"/>';
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $parts['xl/worksheets/sheet' . $n . '.xml'] = xlsxSheetXml((array)($sheet['rows'] ?? []));
    }
    $workbook .= '</sheets></workbook>';

    $stylesRid = 'rIdStyles';
    $wbRels .= '<Relationship Id="' . $stylesRid . '"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
        . ' Target="styles.xml"/>';
    $wbRels .= '</Relationships>';
    $contentTypes .= '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';

    $entries = [
        '[Content_Types].xml'        => $contentTypes,
        '_rels/.rels'                => $rootRels,
        'xl/workbook.xml'            => $workbook,
        'xl/_rels/workbook.xml.rels' => $wbRels,
        'xl/styles.xml'              => $styles,
    ];
    foreach ($parts as $name => $xml) $entries[$name] = $xml;

    return zipCreate($entries);
}