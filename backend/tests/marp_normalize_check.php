<?php

// Self-check MarpGenerator::normalize — no framework boot required.
// Run: docker exec ai-chat-backend php tests/marp_normalize_check.php

require __DIR__.'/../vendor/autoload.php';

use App\Support\MarpGenerator;

$method = (new ReflectionClass(MarpGenerator::class))->getMethod('normalize');
$method->setAccessible(true);
$normalize = fn (string $md) => $method->invoke(null, $md, [
    'accent' => '#ea580c',
    'accent2' => '#e11d48',
    'bg' => '#fffbf7',
    'ink' => '#3b1f12',
]);

$src = <<<'MD'
---
marp: true
theme: gaia
paginate: true
backgroundColor: #f8fafc
color: #1e293b
style: |
  section { padding: 30px 40px; }
  .card { background: #fff; }
---

<!-- _class: lead -->

# Judul

---

## Kinerja

<div class="grid-3"><div class="card">88%</div></div>
MD;

$out = $normalize($src);
$noTheme = $normalize("---\nmarp: true\npaginate: true\n---\n\n## Halo\n\n<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>\n");
$safe = (new ReflectionClass(MarpGenerator::class))->getMethod('safeName');
$safe->setAccessible(true);

$checks = [
    'tema dipaksa notebook' => str_contains($out, 'theme: notebook'),
    'palet disuntik' => str_contains($out, '--accent:#ea580c'),
    'style: | dibuang' => ! str_contains($out, 'padding: 30px 40px'),
    'backgroundColor/color dibuang' => ! str_contains($out, 'backgroundColor') && ! str_contains($out, '#1e293b'),
    'kelas contoh bertahan' => str_contains($out, 'class="grid-3"') && str_contains($out, 'class="card"'),
    'directive _class bertahan' => str_contains($out, '_class: lead'),
    'frontmatter tanpa theme tetap notebook' => str_contains($noTheme, 'theme: notebook'),
    'tabel HTML bertahan' => str_contains($noTheme, '<table>') && str_contains($noTheme, '<tbody>'),
    'nama berkas dirapikan' => $safe->invoke(null, '  Rencana: "BOSP" & Kinerja  ') === 'Rencana BOSP Kinerja',
];

foreach ($checks as $name => $ok) {
    if (! $ok) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
}

echo 'OK marp normalize ('.count($checks)." checks)\n";
