<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Laporan kinerja bulanan e-SAKIP: ringkasan, matriks 5 indikator, dan rincian.
// shortcut: DOCX minimal tanpa kop/logo/tanda tangan; tambahkan saat format
// resmi instansi sudah final.
class PerformanceReportBuilder
{
    private const FONT = 'Arial';

    public static function save(array $doc, int $userId, ?int $sessionId = null): Media
    {
        $bytes = self::docxBytes($doc);
        $filename = self::safeFilename((string) ($doc['filename'] ?? 'Laporan-Kinerja.docx'));

        $rel = 'media/'.$userId.'/'.Str::uuid().'.docx';
        $full = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $bytes);

        return Media::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => strlen($bytes),
            'path' => $rel,
            'preview' => FilePreview::extract($full),
            'model' => 'performance-report',
        ]);
    }

    public static function docxBytes(array $doc): string
    {
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',

            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:docDefaults><w:rPrDefault><w:rPr>'
                .'<w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:eastAsia="'.self::FONT.'" w:cs="'.self::FONT.'"/>'
                .'<w:sz w:val="20"/><w:szCs w:val="20"/>'
                .'</w:rPr></w:rPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
                .'</w:styles>',

            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .self::body($doc)
                .'<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
                .'<w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850" w:header="500" w:footer="500" w:gutter="0"/>'
                .'</w:sectPr></w:body></w:document>',
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
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

    private static function body(array $d): string
    {
        $x = self::p((string) ($d['title'] ?? 'LAPORAN KINERJA BULANAN'), [
            'bold' => true, 'size' => 15, 'align' => 'center', 'after' => 40,
        ]);
        $x .= self::p((string) ($d['subtitle'] ?? 'Integrasi e-SAKIP'), [
            'size' => 11, 'align' => 'center', 'after' => 40,
        ]);
        $x .= self::p('Dibuat: '.(string) ($d['generated_at'] ?? Carbon::now()->locale('id')->translatedFormat('d F Y H:i')).' WITA', [
            'size' => 9, 'align' => 'center', 'after' => 180,
        ]);

        $x .= self::p('RINGKASAN', ['bold' => true, 'size' => 11, 'after' => 80]);
        $x .= self::table($d['summary'] ?? [], [2600, 9000], true, false);
        $x .= self::p('', ['after' => 100]);

        $x .= self::p('MATRIKS KINERJA PER INDIKATOR', ['bold' => true, 'size' => 11, 'after' => 80]);
        $x .= self::table($d['matrix'] ?? [], [4200, 1700, 1700, 1700, 1700], true, true);
        $x .= self::p('', ['after' => 100]);

        $x .= self::p('RINCIAN KEGIATAN', ['bold' => true, 'size' => 11, 'after' => 80]);
        $x .= self::table(
            $d['details'] ?? [],
            [600, 1700, 4200, 2100, 900, 1100, 2600, 1600],
            true,
            true
        );

        $x .= self::p((string) ($d['note'] ?? 'Laporan ini dihasilkan otomatis dari Todo dan Kalender e-SAKIP.'), [
            'size' => 8, 'italic' => true, 'before' => 180, 'after' => 0,
        ]);

        return $x;
    }

    private static function p(string $text, array $o = []): string
    {
        $half = ((int) ($o['size'] ?? 10)) * 2;

        $rPr = '<w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:eastAsia="'.self::FONT.'" w:cs="'.self::FONT.'"/>';
        if (! empty($o['bold'])) {
            $rPr .= '<w:b/>';
        }
        if (! empty($o['italic'])) {
            $rPr .= '<w:i/>';
        }
        $rPr .= '<w:sz w:val="'.$half.'"/><w:szCs w:val="'.$half.'"/>';

        $pPr = '<w:spacing w:before="'.(int) ($o['before'] ?? 0).'" w:after="'.(int) ($o['after'] ?? 100).'" w:line="240" w:lineRule="auto"/>';
        if (! empty($o['align'])) {
            $pPr .= '<w:jc w:val="'.self::align((string) $o['align']).'"/>';
        }

        $parts = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if ($i > 0) {
                $parts[] = '<w:br/>';
            }
            foreach (explode("\t", $line) as $j => $seg) {
                if ($j > 0) {
                    $parts[] = '<w:tab/>';
                }
                $parts[] = '<w:t xml:space="preserve">'.self::esc($seg).'</w:t>';
            }
        }

        return '<w:p><w:pPr>'.$pPr.'</w:pPr><w:r>'.$rPr.implode('', $parts).'</w:r></w:p>';
    }

    private static function table(array $rows, array $widths, bool $bordered, bool $boldHeader): string
    {
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>';
        if ($bordered) {
            $xml .= '<w:tblBorders>';
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
                $xml .= '<w:'.$edge.' w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
            }
            $xml .= '</w:tblBorders>';
        }
        $xml .= '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
        for ($i = 0; $i < $cols; $i++) {
            $xml .= '<w:gridCol w:w="'.((int) ($widths[$i] ?? 1800)).'"/>';
        }
        $xml .= '</w:tblGrid>';

        foreach (array_values($rows) as $rowIndex => $row) {
            $xml .= '<w:tr>';
            foreach (array_values($row) as $i => $cell) {
                $bold = $boldHeader && $rowIndex === 0;
                if (is_array($cell)) {
                    $bold = $bold || ! empty($cell['bold']);
                    $cell = (string) ($cell['text'] ?? '');
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.((int) ($widths[$i] ?? 1800)).'" w:type="dxa"/></w:tcPr>'
                    .self::p((string) $cell, ['bold' => $bold, 'size' => 9, 'after' => 30, 'before' => 30])
                    .'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    private static function align(string $align): string
    {
        return in_array($align, ['left', 'center', 'right', 'both'], true) ? $align : 'left';
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(trim($name)) ?: 'Laporan-Kinerja.docx';
        if (! preg_match('/\.docx$/i', $name)) {
            $name .= '.docx';
        }

        return mb_substr($name, 0, 120);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
