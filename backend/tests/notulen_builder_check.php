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
        'Tempat' => 'Tidak disebutkan dalam transkrip',
        'Peserta' => 'Kepala Bidang Teknis, KPA, PPT, dan Operator',
    ],
    'ringkasan' => ['Rapat membahas pengelolaan Dana BOSP tahun anggaran 2027.'],
    'agenda' => ['Pembukaan dan kehadiran peserta', 'Evaluasi penyerapan dana'],
    'pembahasan' => [
        [
            'judul' => 'A. Pembukaan dan arahan pimpinan',
            'paragraf' => ['Rapat dibuka oleh Pimpinan Rapat dengan salam dan ucapan terima kasih.'],
            'poin' => ['Prinsip rapat rutin adalah evaluasi realisasi serapan anggaran'],
        ],
        [
            'judul' => 'B. Komponen Satuan Biaya per Siswa per Tahun',
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
    'penanda_tangan' => [['Pimpinan Kegiatan', "Bapak Sekretaris\nNIP. 19750513 1998031 004"], ['Notulen', 'Kasubbag Perencanaan']],
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
    'word/media/kaltim-logo.png',
];
foreach ($required as $name) {
    if ($zip->getFromName($name) === false) {
        $fail("missing $name");
    }
}

foreach (['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels', 'word/document.xml', 'word/styles.xml'] as $name) {
    if (function_exists('simplexml_load_string') && @simplexml_load_string((string) $zip->getFromName($name)) === false) {
        $fail("malformed XML in $name");
    }
}

$xml = (string) $zip->getFromName('word/document.xml');
foreach ([
    'NOTULENSI RAPAT',
    'RINGKASAN',
    'AGENDA RAPAT',
    'A. PEMBUKAAN DAN PENGANTAR RAPAT',
    'B. PAPARAN / PEMBAHASAN MATERI UTAMA',
    'C. SESI DISKUSI, TANGGAPAN, DAN TANYA JAWAB',
    'D. KESIMPULAN RAPAT',
    'E. TINDAK LANJUT',
    'PENUTUP',
    'DOKUMENTASI RAPAT',
] as $heading) {
    if (! str_contains($xml, $heading)) {
        $fail("missing section $heading");
    }
}
foreach (['Kasubbag Perencanaan', 'Rp3.500.000', 'DINAS PENDIDIKAN DAN KEBUDAYAAN', 'Samarinda, 20 Oktober 2026', 'NIP.', ': Selasa, 20 Oktober 2026'] as $content) {
    if (! str_contains($xml, $content)) {
        $fail("missing content $content");
    }
}
if (! str_contains($xml, '<w:jc w:val="both"/>')) {
    $fail('narrative text is not justified');
}
if (! str_contains($xml, 'w:val="double"')) {
    $fail('double kop border missing');
}
if (! str_contains($xml, '<w:gridCol w:w="1500"/><w:gridCol w:w="5787"/>')) {
    $fail('logo-left kop table missing');
}
if (! str_contains($xml, '<w:jc w:val="right"/>')) {
    $fail('signature block is not right-aligned');
}
if (str_contains($xml, 'Tidak disebutkan dalam transkrip')) {
    $fail('unknown identity text leaked into layout');
}
if (! str_contains($xml, ': -')) {
    $fail('empty identity value is not replaced with dash');
}
if (! str_contains($xml, '<w:drawing>') || ! str_contains($xml, 'r:embed="rId2"')) {
    $fail('logo not embedded');
}
if (! str_contains((string) $zip->getFromName('word/_rels/document.xml.rels'), 'media/kaltim-logo.png')) {
    $fail('logo relationship missing');
}
if (! str_contains($xml, '<w:br w:type="page"/>')) {
    $fail('documentation page break missing');
}
if (! str_contains($xml, '<w:tbl>')) {
    $fail('no table rendered');
}
if (substr_count($xml, '<w:tbl>') < 3) {
    $fail('expected identitas, action items, and signature tables');
}

$zip->close();
unlink($tmp);

// Kop terkunci: data LLM tidak boleh mengubah header statis.
$doc['kop'] = [
    'pemerintah' => 'PEMERINTAH KOTA CONTOH',
    'instansi' => 'Dinas Contoh',
    'alamat' => 'Jalan Contoh Nomor 1',
];
$doc['kepala'] = ['KOP PALSU DARI LLM'];
$tmp2 = tempnam(sys_get_temp_dir(), 'chk').'.docx';
file_put_contents($tmp2, NotulenBuilder::docxBytes($doc));
$zip2 = new ZipArchive();
if ($zip2->open($tmp2) !== true) {
    $fail('locked kop: not a valid zip/docx');
}
$xml2 = (string) $zip2->getFromName('word/document.xml');
$zip2->close();
unlink($tmp2);
foreach ([
    'PEMERINTAH PROVINSI KALIMANTAN TIMUR',
    'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'Jalan Basuki Rahmat Nomor 5 Samarinda 75112',
    'Telepon (0541) 743580 – 732526 – 7410062, FAX. 756934 – 743055',
    'Website : disdikbud.kaltimprov.go.id | Email : disdikbud@kaltimprov.go.id',
] as $line) {
    if (! str_contains($xml2, $line)) {
        $fail("locked kop line missing: $line");
    }
}
if (str_contains($xml2, 'KOP PALSU') || str_contains($xml2, 'KOTA CONTOH')) {
    $fail('LLM data leaked into locked kop');
}

// JSON model cacat: array action_items tidak ditutup sebelum key "penutup".
$broken = '{"filename":"Notulen.docx","action_items":[["Tim A","Kirim daftar"],["Tim B","Verifikasi"],"penutup":["Selesai"],"catatan":["x"]}';
$repaired = NotulenBuilder::decode($broken);
if (! is_array($repaired)
    || count($repaired['action_items'] ?? []) !== 2
    || ($repaired['penutup'][0] ?? null) !== 'Selesai'
    || ($repaired['catatan'][0] ?? null) !== 'x') {
    $fail('decode failed to repair missing array close');
}
$truncated = NotulenBuilder::decode('{"filename":"Notulen.docx","catatan":["terpotong"');
if (($truncated['catatan'][0] ?? null) !== 'terpotong') {
    $fail('decode failed to close truncated json');
}
if (NotulenBuilder::decode('bukan json') !== null) {
    $fail('decode accepted garbage');
}
$wrapped = NotulenBuilder::decode('Berikut JSON-nya: {"filename":"N.docx","judul":"X"} semoga membantu');
if (($wrapped['judul'] ?? null) !== 'X') {
    $fail('decode failed to strip prose around json');
}
$escaped = NotulenBuilder::decode('{"filename":"N.docx","judul":"X","catatan":["Timur\NIP. 1","x\qy"]}');
if (($escaped['catatan'][0] ?? null) !== "Timur\nIP. 1" || ($escaped['catatan'][1] ?? null) !== 'xqy') {
    $fail('decode failed to repair invalid escapes');
}
$swapped = NotulenBuilder::decode('{"filename":"N.docx","identitas":{"Agenda":["a","b"},"ringkasan":[]}');
if (($swapped['ringkasan'] ?? null) !== [] || ($swapped['identitas']['Agenda'] ?? null) !== ['a', 'b']) {
    $fail('decode failed to repair swapped closing brackets');
}

echo 'OK: NotulenBuilder produced a valid docx ('.strlen($bytes)." bytes)\n";
