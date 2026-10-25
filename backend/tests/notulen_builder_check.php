<?php

// Self-check for NotulenBuilder::docxBytes() — no framework required.
// Run: docker exec ai-chat-backend php tests/notulen_builder_check.php

require __DIR__.'/../app/Support/NotulenBuilder.php';

use App\Support\NotulenBuilder;

$doc = [
    'filename' => 'Notulen_Rapat_BOSP.docx',
    'subjudul' => 'Rapat Koordinasi Pengelola Dana BOSP',
    'identitas' => [
        'Hari/Tanggal' => 'Selasa, 20 Oktober 2026',
        'Tempat' => 'Daring (Virtual) melalui Zoom',
        'Peserta' => 'Kepala Bidang Teknis, KPA, PPT, dan Operator',
    ],
    'ringkasan' => ['Rapat membahas pengelolaan Dana BOSP tahun anggaran 2027.'],
    'agenda' => ['Pembukaan dan kehadiran peserta', 'Evaluasi penyerapan dana'],
    'pembahasan' => [
        [
            'judul' => 'A. Komponen Satuan Biaya per Siswa per Tahun',
            'paragraf' => ['Angka satuan biaya sementara Rp3.500.000 per siswa per tahun.'],
            'poin' => ['Kebutuhan Belajar', 'Operasional Sekolah'],
        ],
    ],
    'tanya_jawab' => ['Tidak ada pertanyaan dan jawaban tercatat dalam rekaman.'],
    'keputusan' => ['Target penyerapan ditetapkan minimal 4% setiap minggu.'],
    'action_items' => [
        ['Penanggung Jawab', 'Tindakan'],
        ['Kasubbag Perencanaan', 'Membuat kertas kerja pemetaan kebutuhan persiswa per tahun.'],
    ],
    'penutup' => ['Demikian notulensi ini disusun berdasarkan rekaman rapat.'],
    'penanda_tangan' => [['Pimpinan Kegiatan', 'Bapak Sekretaris'], ['Notulen', 'Kasubbag Perencanaan']],
    'catatan' => ['Sumber: rekaman rapat 20 Oktober 2026.'],
];

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};

$bytes = NotulenBuilder::docxBytes($doc);
if ($bytes === '') {
    $fail('empty output');
}

$tmp = tempnam(sys_get_temp_dir(), 'chk').'.docx';
file_put_contents($tmp, $bytes);

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    $fail('not a valid zip/docx');
}

$required = [
    '[Content_Types].xml',
    '_rels/.rels',
    'word/_rels/document.xml.rels',
    'word/document.xml',
    'word/styles.xml',
];
foreach ($required as $name) {
    if ($zip->getFromName($name) === false) {
        $fail("missing $name");
    }
}

foreach ($required as $name) {
    if (function_exists('simplexml_load_string') && @simplexml_load_string((string) $zip->getFromName($name)) === false) {
        $fail("malformed XML in $name");
    }
}

$xml = (string) $zip->getFromName('word/document.xml');
foreach (['RINGKASAN', 'AGENDA RAPAT', 'POKOK-POKOK PEMBAHASAN', 'KEPUTUSAN', 'ACTION ITEMS', 'PENUTUP'] as $heading) {
    if (! str_contains($xml, $heading)) {
        $fail("missing section $heading");
    }
}
foreach (['Kasubbag Perencanaan', 'Rp3.500.000', 'DINAS PENDIDIKAN DAN KEBUDAYAAN'] as $content) {
    if (! str_contains($xml, $content)) {
        $fail("missing content $content");
    }
}
if (! str_contains($xml, '<w:tbl>')) {
    $fail('no table rendered');
}
if (substr_count($xml, '<w:tbl>') < 3) {
    $fail('expected identitas, action items, and signature tables');
}

$zip->close();
unlink($tmp);

echo 'OK: NotulenBuilder produced a valid docx ('.strlen($bytes)." bytes)\n";
