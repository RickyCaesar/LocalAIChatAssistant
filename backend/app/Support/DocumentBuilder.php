<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Str;

// Membuat spreadsheet XLSX dari struktur JSON yang dikirim agent lewat
// ACTION: DOC_BUILD. ponytail: penulis XLSX minimal pakai ZipArchive (ext `zip`
// sudah aktif) — cukup untuk tabel & daftar berjudul, TANPA rumus/grafik/pivot.
// Kalau butuh fitur itu, ganti ke phpoffice/phpspreadsheet.
class DocumentBuilder
{
    // Struktur input:
    // {
    //   "filename": "penugasan-reviewer.xlsx",     // opsional
    //   "sheet": "TKA",                            // opsional
    //   "title": "Penugasan Reviewer TKA",         // opsional
    //   "blocks": [
    //     {"heading": "Jadwal", "columns": ["No","Reviewer","Tanggal"],
    //      "rows": [["1","Budi","2026-05-01"]]},
    //     {"heading": "Tata Tertib", "rows": [["Datang 15 menit lebih awal"]]}
    //   ]
    // }
    // `columns`/`rows` boleh juga di level atas (dianggap satu blok).
    public static function save(array $doc, int $userId, ?int $sessionId): Media
    {
        $bytes = self::xlsxBytes($doc);
        $filename = self::safeFilename((string) ($doc['filename'] ?? 'dokumen.xlsx'));

        $rel = 'media/'.$userId.'/'.Str::uuid().'.xlsx';
        $full = storage_path('app/private/'.$rel);
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($full, $bytes);

        return Media::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => strlen($bytes),
            'path' => $rel,
            'preview' => FilePreview::extract($full),
            'model' => (string) env('AI_MODEL', 'hermes-agent'),
        ]);
    }

    // Murni: kembalikan byte XLSX tanpa menyentuh DB/storage (mudah diuji).
    public static function xlsxBytes(array $doc): string
    {
        $sheetName = self::sheetName((string) ($doc['sheet'] ?? 'Sheet1'));
        $title = isset($doc['title']) ? (string) $doc['title'] : '';
        $blocks = self::blocks($doc);

        $colCount = 1;
        foreach ($blocks as $b) {
            $colCount = max($colCount, count($b['columns']));
            foreach ($b['rows'] as $row) {
                $colCount = max($colCount, count($row));
            }
        }

        $strings = [];
        $index = [];
        $strId = static function (string $v) use (&$strings, &$index): int {
            if (! array_key_exists($v, $index)) {
                $strings[] = $v;
                $index[$v] = count($strings) - 1;
            }

            return $index[$v];
        };

        $rowsXml = '';
        $row = 1;
        $emit = static function (array $cells, int $r, int $style = 0) use (&$rowsXml, $strId): void {
            $xml = '<row r="'.$r.'">';
            foreach (array_values($cells) as $c => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $ref = self::colLetter($c).$r;
                $s = $style > 0 ? ' s="'.$style.'"' : '';
                $isNumber = is_int($val) || is_float($val)
                    || (is_string($val) && preg_match('/^-?\d+(\.\d+)?$/', $val) === 1);
                if ($isNumber) {
                    $xml .= '<c r="'.$ref.'"'.$s.'><v>'.self::esc((string) $val).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="s"'.$s.'><v>'.$strId((string) $val).'</v></c>';
                }
            }
            $rowsXml .= $xml.'</row>';
        };

        if ($title !== '') {
            $emit([$title], $row, 2);
            $row += 2;
        }
        foreach ($blocks as $b) {
            if ($b['heading'] !== '') {
                $emit([$b['heading']], $row, 1);
                $row++;
            }
            if ($b['columns'] !== []) {
                $emit($b['columns'], $row, 1);
                $row++;
            }
            foreach ($b['rows'] as $dataRow) {
                $emit($dataRow, $row, 0);
                $row++;
            }
            $row++;
        }

        $cols = $colCount >= 1
            ? '<cols><col min="1" max="'.$colCount.'" width="24" customWidth="1"/></cols>'
            : '';

        $sstXml = '';
        foreach ($strings as $s) {
            $sstXml .= '<si><t xml:space="preserve">'.self::esc($s).'</t></si>';
        }

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',

            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::esc($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',

            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                .'</Relationships>',

            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="3">'
                .'<font><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
                .'</fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'</cellXfs>'
                .'</styleSheet>',

            'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'
                .$sstXml
                .'</sst>',

            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .$cols
                .'<sheetData>'.$rowsXml.'</sheetData>'
                .'</worksheet>',
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function blocks(array $doc): array
    {
        $out = [];
        if (is_array($doc['blocks'] ?? null)) {
            foreach ($doc['blocks'] as $b) {
                if (is_array($b)) {
                    $out[] = self::normalizeBlock($b);
                }
            }
        }

        if ($out === [] && (isset($doc['rows']) || isset($doc['columns']))) {
            $out[] = self::normalizeBlock($doc);
        }

        return $out;
    }

    private static function normalizeBlock(array $b): array
    {
        return [
            'heading' => isset($b['heading']) ? (string) $b['heading'] : '',
            'columns' => array_map('strval', array_values((array) ($b['columns'] ?? []))),
            'rows' => array_map(
                fn ($row) => array_map('strval', array_values((array) $row)),
                array_values((array) ($b['rows'] ?? []))
            ),
        ];
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(trim($name)) ?: 'dokumen.xlsx';
        if (! preg_match('/\.xlsx$/i', $name)) {
            $name .= '.xlsx';
        }

        return mb_substr($name, 0, 120);
    }

    private static function sheetName(string $name): string
    {
        $name = trim(preg_replace('#[\\\\/*?:\[\]]#', ' ', $name) ?? $name);

        return $name === '' ? 'Sheet1' : mb_substr($name, 0, 31);
    }

    private static function colLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $rem = ($i - 1) % 26;
            $s = chr(65 + $rem).$s;
            $i = intdiv($i - 1, 26);
        }

        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
