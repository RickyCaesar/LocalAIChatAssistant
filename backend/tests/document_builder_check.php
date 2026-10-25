<?php

// Self-check for DocumentBuilder::xlsxBytes() — no framework required.
// Run: docker exec ai-chat-backend php tests/document_builder_check.php

require __DIR__.'/../app/Support/DocumentBuilder.php';

use App\Support\DocumentBuilder;

$doc = [
    'filename' => 'penugasan-reviewer.xlsx',
    'sheet' => 'TKA',
    'title' => 'Penugasan Reviewer Tes Kemampuan Akademik',
    'blocks' => [
        [
            'heading' => 'Jadwal Review',
            'columns' => ['No', 'Reviewer', 'Tanggal', 'Sesi'],
            'rows' => [
                ['1', 'Budi Santoso', '2026-05-01', 'Pagi'],
                ['2', 'Siti Aminah', '2026-05-01', 'Siang'],
            ],
        ],
        [
            'heading' => 'Tata Tertib',
            'rows' => [
                ['Datang 15 menit lebih awal'],
                ['Dilarang membawa catatan'],
            ],
        ],
        [
            'heading' => 'Identitas',
            'columns' => ['NISN', 'Nama'],
            'rows' => [
                ['0012345678', 'Ani'],
            ],
        ],
    ],
];

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
};

$bytes = DocumentBuilder::xlsxBytes($doc);
if ($bytes === '') {
    $fail('empty output');
}

$tmp = tempnam(sys_get_temp_dir(), 'chk').'.xlsx';
file_put_contents($tmp, $bytes);

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    $fail('not a valid zip/xlsx');
}

$required = [
    '[Content_Types].xml',
    '_rels/.rels',
    'xl/workbook.xml',
    'xl/worksheets/sheet1.xml',
    'xl/styles.xml',
    'xl/sharedStrings.xml',
];
foreach ($required as $name) {
    if ($zip->getFromName($name) === false) {
        $fail("missing $name");
    }
}

foreach (['xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml', 'xl/sharedStrings.xml'] as $name) {
    if (function_exists('simplexml_load_string') && @simplexml_load_string((string) $zip->getFromName($name)) === false) {
        $fail("malformed XML in $name");
    }
}

$sst = (string) $zip->getFromName('xl/sharedStrings.xml');
if (! str_contains($sst, 'Budi Santoso') || ! str_contains($sst, 'Tata Tertib')) {
    $fail('shared strings missing expected content');
}
if (! str_contains($sst, '0012345678')) {
    $fail('leading-zero ID was not preserved as text');
}

$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
if (! str_contains($sheet, 't="s"') || ! str_contains($sheet, '<row r="1">')) {
    $fail('worksheet malformed');
}
if (str_contains($sheet, '<v>0012345678</v>')) {
    $fail('leading-zero ID was written as a number');
}

$zip->close();
unlink($tmp);

echo 'OK: DocumentBuilder produced a valid xlsx ('.strlen($bytes)." bytes)\n";
