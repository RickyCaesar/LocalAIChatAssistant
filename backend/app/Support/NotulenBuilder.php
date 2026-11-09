<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Str;

// Menyusun DOCX notulen rapat dari struktur JSON (ACTION: NOTULEN_BUILD).
// Polanya mengikuti notulen Dinas Pendidikan dan Kebudayaan Provinsi Kalimantan
// Timur: kop berlogo, judul, identitas rapat, agenda, hasil pembahasan, sesi
// diskusi, kesimpulan, tindak lanjut (tabel), penutup, tanda tangan, dokumentasi.
// shortcut: penulis DOCX minimal pakai ZipArchive (ext `zip` sudah aktif) —
// tanpa header/footer, penomoran halaman, daftar isi; butir memakai karakter
// "•" + indent, bukan list numbering Word. Kalau butuh fitur itu, ganti ke
// phpoffice/phpword.
class NotulenBuilder
{
    private const FONT = 'Times New Roman';

    private const LOGO = __DIR__.'/../../resources/notulen/kaltim-logo.png';

    // Kop surat baku dan terkunci: tidak boleh diubah oleh data/LLM.
    private const KOP = [
        'PEMERINTAH PROVINSI KALIMANTAN TIMUR',
        'DINAS PENDIDIKAN DAN KEBUDAYAAN',
        'Jalan Basuki Rahmat Nomor 5 Samarinda 75112',
        'Telepon (0541) 743580 – 732526 – 7410062, FAX. 756934 – 743055',
        'Website : disdikbud.kaltimprov.go.id | Email : disdikbud@kaltimprov.go.id',
    ];

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
        $logo = is_file(self::LOGO) ? (string) file_get_contents(self::LOGO) : '';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .($logo !== '' ? '<Default Extension="png" ContentType="image/png"/>' : '')
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
                .($logo !== '' ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/kaltim-logo.png"/>' : '')
                .'</Relationships>',

            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:docDefaults><w:rPrDefault><w:rPr>'
                .'<w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:eastAsia="'.self::FONT.'" w:cs="'.self::FONT.'"/>'
                .'<w:sz w:val="22"/><w:szCs w:val="22"/>'
                .'</w:rPr></w:rPrDefault></w:docDefaults>'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:pPr><w:jc w:val="both"/></w:pPr></w:style>'
                .'</w:styles>',

            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
                .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
                .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
                .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
                .self::body($doc)
                .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                .'<w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>'
                .'</w:sectPr></w:body></w:document>',
        ];
        if ($logo !== '') {
            $parts['word/media/kaltim-logo.png'] = $logo;
        }

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

        // Header statis: apa pun isi JSON dari LLM, kop tidak pernah berubah.
        $kepala = self::KOP;
        $last = count($kepala) - 1;
        $kopLines = '';
        foreach ($kepala as $i => $line) {
            $kopLines .= self::p($line, [
                'bold' => $i < 2,
                'size' => $i < 2 ? 14 : 10,
                'align' => 'center',
                'after' => $i === $last ? 60 : 20,
            ]);
        }
        $logo = self::logo();
        if ($logo !== '') {
            // Logo kiri, teks tetap di tengah halaman (kolom kanan kosong penyeimbang).
            $x .= '<w:tbl><w:tblPr><w:tblW w:w="8787" w:type="dxa"/><w:jc w:val="left"/><w:tblLayout w:type="fixed"/>'
                .'<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr>'
                .'<w:tblGrid><w:gridCol w:w="1500"/><w:gridCol w:w="5787"/><w:gridCol w:w="1500"/></w:tblGrid>'
                .'<w:tr><w:tc><w:tcPr><w:tcW w:w="1500" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'.$logo.'</w:tc>'
                .'<w:tc><w:tcPr><w:tcW w:w="5787" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'.$kopLines.'</w:tc>'
                .'<w:tc><w:tcPr><w:tcW w:w="1500" w:type="dxa"/></w:tcPr><w:p/></w:tc></w:tr></w:tbl>';
        } else {
            $x .= $kopLines;
        }
        // Garis ganda pemisah kop.
        $x .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="double" w:sz="6" w:space="1" w:color="000000"/></w:pBdr>'
            .'<w:spacing w:before="0" w:after="240"/></w:pPr></w:p>';

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
                    ['text' => ': '.self::isiIdentitas($isi)],
                ];
            }
            $x .= self::table($rows, [2600, 6187], false, '', 'left');
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
            foreach ($agenda as $i => $item) {
                $item = preg_replace('/^\d+[.)]\s*/', '', $item) ?? $item;
                $x .= self::p(($i + 1).'. '.$item, ['ind' => 397]);
            }
        }

        // Kerangka adaptif: blok pembahasan pertama = pembukaan, sisanya = materi utama.
        $pembahasan = array_values(is_array($d['pembahasan'] ?? null) ? $d['pembahasan'] : []);
        if ($pembahasan !== []) {
            $x .= self::heading('A. PEMBUKAAN DAN PENGANTAR RAPAT');
            foreach ($pembahasan as $i => $blok) {
                if ($i === 1) {
                    $x .= self::heading('B. PAPARAN / PEMBAHASAN MATERI UTAMA');
                }
                if (! is_array($blok)) {
                    $x .= self::p((string) $blok);
                    continue;
                }
                $judul = trim((string) preg_replace('/^[A-Z][.)]\s*/', '', trim((string) ($blok['judul'] ?? ''))));
                if ($judul !== '') {
                    $judul = $i === 0 ? $judul : 'B.'.$i.' '.$judul;
                    $x .= self::p($judul, ['bold' => true, 'size' => 11, 'align' => 'left', 'before' => 120, 'after' => 60]);
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
            $x .= self::heading('C. SESI DISKUSI, TANGGAPAN, DAN TANYA JAWAB');
            foreach ($tanya as $p) {
                $x .= self::p($p);
            }
        }

        $keputusan = self::lines($d['keputusan'] ?? []);
        if ($keputusan !== []) {
            $x .= self::heading('D. KESIMPULAN RAPAT');
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
                $x .= self::heading('E. TINDAK LANJUT');
                $header = ['Penanggung Jawab', 'Tindakan'];
                if (array_map('strval', $rows[0]) !== $header) {
                    array_unshift($rows, $header);
                }
                $rows[0] = array_map(fn ($c) => ['text' => $c, 'bold' => true, 'align' => 'center'], $rows[0]);
                $x .= self::table($rows, [2600, 6187], true, '', 'both');
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

        // Tanda tangan: kota/tanggal, lalu dua kolom sejajar.
        $ttd = $d['penanda_tangan'] ?? [];
        if (is_array($ttd) && $ttd !== []) {
            $x .= self::p(self::kotaTanggal($d), ['align' => 'right', 'after' => 240]);
            $x .= self::signatures($ttd);
        }

        foreach (self::lines($d['catatan'] ?? []) as $catatan) {
            $x .= self::p($catatan, ['italic' => true]);
        }

        // Dokumentasi rapat pada halaman tersendiri.
        $x .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        $x .= self::heading('DOKUMENTASI RAPAT');
        $x .= self::p('Dokumentasi foto peserta rapat dilampirkan pada halaman ini.', [
            'italic' => true, 'align' => 'center', 'after' => 240,
        ]);

        // Word minta paragraf setelah tabel terakhir; paragraf kosong ini juga
        // menutup dokumen kalau isinya cuma tabel.
        $x .= self::p('');

        return $x;
    }

    private static function logo(): string
    {
        if (! is_file(self::LOGO)) {
            return '';
        }
        // ~2,0 x 2,5 cm, logo di tengah atas kop.
        $cx = 736000;
        $cy = 900000;

        return '<w:p><w:pPr><w:spacing w:before="0" w:after="120"/><w:jc w:val="center"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="1" name="Logo Kaltim"/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="kaltim-logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private static function kotaTanggal(array $d): string
    {
        $tanggal = trim((string) ($d['tanggal_tanda_tangan'] ?? ''));
        if ($tanggal === '') {
            foreach (self::pairs($d['identitas'] ?? []) as [$label, $isi]) {
                if (stripos($label, 'tanggal') !== false) {
                    $tanggal = $isi;
                    break;
                }
            }
        }
        $tanggal = trim((string) preg_replace('/^(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),?\s*/i', '', $tanggal));
        $kota = trim((string) ($d['kota'] ?? '')) ?: 'Samarinda';

        return $kota.', '.($tanggal !== '' ? $tanggal : '........................');
    }

    // penanda_tangan: [[peran, "Nama\nNIP..."]] atau [[peran, nama, nip]].
    // Dirender dua kolom sejajar seperti notulen resmi.
    private static function signatures(array $ttd): string
    {
        $cols = [];
        foreach ($ttd as $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = trim((string) ($row[0] ?? ''));
            if (count($row) >= 3) {
                $name = trim((string) ($row[1] ?? ''));
                $nip = trim((string) ($row[2] ?? ''));
            } else {
                $rest = trim((string) ($row[1] ?? ''));
                $parts = preg_split('/\R/', $rest) ?: [];
                $name = trim((string) ($parts[0] ?? ''));
                $nip = trim((string) ($parts[1] ?? ''));
            }
            if ($nip === '' && preg_match('/\s+NIP\.?\s*/i', $name)) {
                [$name, $nip] = preg_split('/\s+NIP\.?\s*/i', $name, 2);
                $nip = 'NIP. '.trim((string) $nip);
            }
            // Nama/NIP yang tidak diketahui jangan mencetak kalimat panjang.
            $name = self::isiIdentitas($name);
            $nip = self::isiIdentitas(trim((string) preg_replace('/^NIP\.?\s*/i', '', $nip)));
            $nip = $nip === '-' ? '' : 'NIP. '.$nip;
            if ($role !== '' || $name !== '' || $nip !== '') {
                $cols[] = [$role, $name, $nip];
            }
        }

        $x = '';
        foreach (array_chunk($cols, 2) as $pair) {
            $two = count($pair) === 2;
            // Penanda tangan utama di kolom kanan (lazim: notulis di kiri).
            $rows = $two
                ? [
                    [self::role($pair[1][0]), self::role($pair[0][0])],
                    [$pair[1][1], $pair[0][1]],
                    [$pair[1][2], $pair[0][2]],
                ]
                : [[self::role($pair[0][0])], [$pair[0][1]], [$pair[0][2]]];
            // Blok tanda tangan di kanan bawah: tabel menyempit dan rata kanan.
            $x .= self::table($rows, $two ? [3000, 3000] : [3000], false, 'right', 'left');
            $x .= self::p('');
        }

        return $x;
    }

    private static function role(string $role): string
    {
        $role = rtrim(trim($role), ',');

        return $role === '' ? '' : $role.',';
    }

    private static function heading(string $text): string
    {
        return self::p($text, ['bold' => true, 'size' => 12, 'align' => 'left', 'keepNext' => true, 'before' => 240, 'after' => 120]);
    }

    private static function bullet(string $text): string
    {
        return self::p("\u{2022}\t".$text, ['bullet' => true, 'after' => 60]);
    }

    // Opsi: bold, italic, size (pt), align, before/after (twip), bullet, ind, keepNext.
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

        // Urutan anak w:pPr itu wajib (CT_PPr): keepNext, tabs, spacing, ind, jc.
        $pPr = '';
        if (! empty($o['keepNext'])) {
            $pPr .= '<w:keepNext/>';
        }
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
        } else {
            // Default naskah dinas: rata kanan-kiri.
            $pPr .= '<w:jc w:val="both"/>';
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

    // Baris = array sel; sel = string atau ['text' => ..., 'bold' => true, 'align' => ...].
    private static function table(array $rows, array $widths, bool $bordered = true, string $align = '', string $cellAlign = ''): string
    {
        $cols = 0;
        foreach ($rows as $row) {
            $cols = max($cols, count($row));
        }

        // Urutan anak w:tblPr itu wajib (CT_TblPr): tblW, jc, tblBorders, tblLayout.
        // Lebar tabel = jumlah kolom (dxa) supaya jc:right benar-benar menggeser tabel.
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum(array_map('intval', $widths)).'" w:type="dxa"/>';
        if ($align !== '') {
            $xml .= '<w:jc w:val="'.self::align($align).'"/>';
        }
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
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach (array_values($row) as $i => $cell) {
                $bold = false;
                $ca = $cellAlign;
                if (is_array($cell)) {
                    $bold = ! empty($cell['bold']);
                    $ca = (string) ($cell['align'] ?? $ca);
                    $cell = (string) ($cell['text'] ?? '');
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.((int) ($widths[$i] ?? 2000)).'" w:type="dxa"/></w:tcPr>'
                    .self::p((string) $cell, ['bold' => $bold, 'after' => 40, 'before' => 40, 'align' => $ca])
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

    // Data kosong/tidak diketahui jangan mencetak kalimat panjang di tabel.
    private static function isiIdentitas(string $isi): string
    {
        $isi = trim($isi);
        if ($isi === '' || preg_match('/^tidak (disebutkan|tercatat|ada|ditemukan)( dalam transkrip)?\.?$/i', $isi)) {
            return '-';
        }

        return $isi;
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
