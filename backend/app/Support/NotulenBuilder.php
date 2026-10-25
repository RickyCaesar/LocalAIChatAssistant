<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Str;

// Menyusun DOCX notulen rapat dari struktur JSON (ACTION: NOTULEN_BUILD).
// Polanya mengikuti notulen Dinas Pendidikan dan Kebudayaan Provinsi Kalimantan
// Timur: kop, judul, identitas rapat, ringkasan, agenda, pokok pembahasan,
// tanya-jawab, keputusan, action items (tabel), penutup, tanda tangan.
// shortcut: penulis DOCX minimal pakai ZipArchive (ext `zip` sudah aktif) —
// tanpa gambar, header/footer, penomoran halaman, daftar isi; butil memakai
// karakter "•" + indent, bukan list numbering Word. Kalau butuh fitur itu,
// ganti ke phpoffice/phpword.
class NotulenBuilder
{
    private const FONT = 'Times New Roman';

    public static function save(array $doc, int $userId, ?int $sessionId): Media
    {
        $bytes = self::docxBytes($doc);
        $filename = self::safeFilename((string) ($doc['filename'] ?? 'Notulen.docx'));

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
            'model' => (string) env('AI_MODEL', 'hermes-agent'),
        ]);
    }

    // Murni: kembalikan byte DOCX tanpa menyentuh DB/storage (mudah diuji).
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
                .'<w:sz w:val="22"/><w:szCs w:val="22"/>'
                .'</w:rPr></w:rPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
                .'</w:styles>',

            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .self::body($doc)
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                .'<w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>'
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

    // Susun isi dokumen sesuai pola notulen.
    private static function body(array $d): string
    {
        $x = '';

        $kepala = self::lines($d['kepala'] ?? []);
        if ($kepala === []) {
            $kepala = [
                'PEMERINTAH PROVINSI KALIMANTAN TIMUR',
                'DINAS PENDIDIKAN DAN KEBUDAYAAN',
                'Kalimantan Timur',
            ];
        }
        $last = count($kepala) - 1;
        foreach ($kepala as $i => $line) {
            $x .= self::p($line, [
                'bold' => $i < 2,
                'size' => $i < 2 ? 13 : 11,
                'align' => 'center',
                'after' => $i === $last ? 240 : 40,
            ]);
        }

        $x .= self::p((string) ($d['judul'] ?? 'NOTULENSI RAPAT'), [
            'bold' => true, 'size' => 14, 'align' => 'center', 'after' => 100,
        ]);
        if (($sub = trim((string) ($d['subjudul'] ?? ''))) !== '') {
            $x .= self::p($sub, ['size' => 12, 'align' => 'center', 'after' => 240]);
        }

        // Identitas rapat: label + isi, dua kolom tanpa garis.
        $identitas = self::pairs($d['identitas'] ?? []);
        if ($identitas !== []) {
            $rows = [];
            foreach ($identitas as [$label, $isi]) {
                $rows[] = [
                    ['text' => $label, 'bold' => true],
                    ['text' => $isi],
                ];
            }
            $x .= self::table($rows, [2600, 6187], false);
            $x .= self::p('');
        }

        $ringkasan = self::lines($d['ringkasan'] ?? []);
        if ($ringkasan !== []) {
            $x .= self::heading('RINGKASAN');
            foreach ($ringkasan as $p) {
                $x .= self::p($p);
            }
        }

        $agenda = self::lines($d['agenda'] ?? []);
        if ($agenda !== []) {
            $x .= self::heading('AGENDA RAPAT');
            foreach ($agenda as $item) {
                $x .= self::bullet($item);
            }
        }

        $pembahasan = is_array($d['pembahasan'] ?? null) ? $d['pembahasan'] : [];
        if ($pembahasan !== []) {
            $x .= self::heading('POKOK-POKOK PEMBAHASAN');
            foreach ($pembahasan as $blok) {
                if (! is_array($blok)) {
                    $x .= self::p((string) $blok);
                    continue;
                }
                $judul = trim((string) ($blok['judul'] ?? ''));
                if ($judul !== '') {
                    $x .= self::p($judul, ['bold' => true, 'size' => 11, 'before' => 120, 'after' => 60]);
                }
                foreach (self::lines($blok['paragraf'] ?? []) as $p) {
                    $x .= self::p($p);
                }
                foreach (self::lines($blok['poin'] ?? []) as $poin) {
                    $x .= self::bullet($poin);
                }
            }
        }

        $tanya = self::lines($d['tanya_jawab'] ?? []);
        if ($tanya !== []) {
            $x .= self::heading('PERTANYAAN DAN JAWABAN');
            foreach ($tanya as $p) {
                $x .= self::p($p);
            }
        }

        $keputusan = self::lines($d['keputusan'] ?? []);
        if ($keputusan !== []) {
            $x .= self::heading('KEPUTUSAN');
            foreach ($keputusan as $i => $k) {
                $x .= self::p(($i + 1).'. '.$k, ['ind' => 397]);
            }
        }

        $items = $d['action_items'] ?? [];
        if (is_array($items) && $items !== []) {
            $rows = [];
            foreach ($items as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cells = [];
                foreach (array_values($row) as $cell) {
                    $cells[] = is_array($cell) ? (string) ($cell['text'] ?? '') : (string) $cell;
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
            if ($rows !== []) {
                $x .= self::heading('ACTION ITEMS');
                $header = ['Penanggung Jawab', 'Tindakan'];
                if (array_map('strval', $rows[0]) !== $header) {
                    array_unshift($rows, $header);
                }
                $rows[0] = array_map(fn ($c) => ['text' => $c, 'bold' => true], $rows[0]);
                $x .= self::table($rows, [2600, 6187]);
                $x .= self::p('');
            }
        }

        $penutup = self::lines($d['penutup'] ?? []);
        if ($penutup !== []) {
            $x .= self::heading('PENUTUP');
            foreach ($penutup as $p) {
                $x .= self::p($p);
            }
        }

        // Tanda tangan: dua kolom (kiri | kanan) tanpa garis.
        $ttd = $d['penanda_tangan'] ?? [];
        if (is_array($ttd) && $ttd !== []) {
            $rows = [];
            foreach ($ttd as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = [(string) ($row[0] ?? ''), (string) ($row[1] ?? '')];
            }
            if ($rows !== []) {
                $x .= self::table($rows, [4393, 4394], false);
            }
        }

        foreach (self::lines($d['catatan'] ?? []) as $catatan) {
            $x .= self::p($catatan, ['italic' => true]);
        }

        // Word minta paragraf setelah tabel terakhir; paragraf kosong ini juga
        // menutup dokumen kalau isinya cuma tabel.
        $x .= self::p('');

        return $x;
    }

    private static function heading(string $text): string
    {
        return self::p($text, ['bold' => true, 'size' => 12, 'before' => 240, 'after' => 120]);
    }

    private static function bullet(string $text): string
    {
        return self::p("\u{2022}\t".$text, ['bullet' => true, 'after' => 60]);
    }

    // Opsi: bold, italic, size (pt), align, before/after (twip), bullet, ind.
    private static function p(string $text, array $o = []): string
    {
        $half = ((int) ($o['size'] ?? 11)) * 2;

        $rPr = '<w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:eastAsia="'.self::FONT.'" w:cs="'.self::FONT.'"/>';
        if (! empty($o['bold'])) {
            $rPr .= '<w:b/>';
        }
        if (! empty($o['italic'])) {
            $rPr .= '<w:i/>';
        }
        $rPr .= '<w:sz w:val="'.$half.'"/><w:szCs w:val="'.$half.'"/>';

        // Urutan anak w:pPr itu wajib (CT_PPr): tabs, spacing, ind, jc.
        $pPr = '';
        if (! empty($o['bullet'])) {
            $pPr .= '<w:tabs><w:tab w:val="left" w:pos="567"/></w:tabs>';
        }
        $pPr .= '<w:spacing w:before="'.(int) ($o['before'] ?? 0).'" w:after="'.(int) ($o['after'] ?? 120).'" w:line="276" w:lineRule="auto"/>';
        if (! empty($o['bullet'])) {
            $pPr .= '<w:ind w:left="567" w:hanging="567"/>';
        } elseif (! empty($o['ind'])) {
            $pPr .= '<w:ind w:left="'.(int) $o['ind'].'" w:hanging="'.(int) $o['ind'].'"/>';
        }
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

    // Baris = array sel; sel = string atau ['text' => ..., 'bold' => true].
    private static function table(array $rows, array $widths, bool $bordered = true): string
    {
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }

        // Urutan anak w:tblPr itu wajib (CT_TblPr): tblW, tblBorders, tblLayout.
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>';
        if ($bordered) {
            $xml .= '<w:tblBorders>';
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
                $xml .= '<w:'.$edge.' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
            }
            $xml .= '</w:tblBorders>';
        }
        $xml .= '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
        for ($i = 0; $i < $cols; $i++) {
            $xml .= '<w:gridCol w:w="'.((int) ($widths[$i] ?? 2000)).'"/>';
        }
        $xml .= '</w:tblGrid>';

        foreach ($rows as $row) {
            $xml .= '<w:tr>';
            foreach (array_values($row) as $i => $cell) {
                $bold = false;
                if (is_array($cell)) {
                    $bold = ! empty($cell['bold']);
                    $cell = (string) ($cell['text'] ?? '');
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.((int) ($widths[$i] ?? 2000)).'" w:type="dxa"/></w:tcPr>'
                    .self::p((string) $cell, ['bold' => $bold, 'after' => 40, 'before' => 40])
                    .'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    // String atau array string -> array string bersih.
    private static function lines($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (is_array($v)) {
                $v = (string) ($v['text'] ?? '');
            }
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    // Identitas: {"Label": "Isi"} atau [["Label", "Isi"], ...] -> [[label, isi]].
    private static function pairs($value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $label = trim((string) ($v[0] ?? ''));
                $isi = trim((string) ($v[1] ?? ''));
            } else {
                $label = is_int($k) ? '' : trim((string) $k);
                $isi = trim((string) $v);
            }
            if ($label !== '' || $isi !== '') {
                $out[] = [$label, $isi];
            }
        }

        return $out;
    }

    private static function align(string $align): string
    {
        return in_array($align, ['left', 'center', 'right', 'both'], true) ? $align : 'left';
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(trim($name)) ?: 'Notulen.docx';
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
