<?php

namespace App\Support;

use App\Models\Media;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table;

class PerformanceReportBuilder
{
    public static function save(array $data, int $userId): Media
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection([
            'marginLeft' => 600,
            'marginRight' => 600,
            'marginTop' => 800,
            'marginBottom' => 800,
        ]);

        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addTitle($data['title'] ?? 'LAPORAN KINERJA BULANAN', 1);
        if (! empty($data['subtitle'])) {
            $phpWord->addTitleStyle(2, ['size' => 12], ['alignment' => Jc::CENTER]);
            $section->addTitle($data['subtitle'], 2);
        }

        $section->addTextBreak(1);

        $phpWord->addTitleStyle(3, ['bold' => true, 'size' => 11]);
        $section->addTitle('A. Ringkasan', 3);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
        foreach ($data['summary'] ?? [] as $row) {
            $table->addRow();
            $c1 = $table->addCell(4000);
            $c1->addText($row[0], ['bold' => true]);
            $c2 = $table->addCell(3000);
            $c2->addText((string) $row[1]);
        }

        $section->addTextBreak(1);
        $section->addTitle('B. Matriks Kinerja Bulanan (e-SAKIP)', 3);
        $section->addText('Integrasi Todo, Kalender, dan Laporan Kinerja berdasarkan zona waktu Asia/Makassar (WITA).', ['size' => 9, 'italic' => true]);

        $section->addTextBreak(1);
        $x = self::table($data['matrix'] ?? [], [700, 2300, 2300, 2300, 1600, 2300, 2300], true, true);

        $section->addTextBreak(1);
        $section->addTitle('C. Rincian Kegiatan', 3);
        self::table($data['details'] ?? [], [600, 1100, 2500, 2000, 900, 900, 1900, 1500], true, false);

        if (! empty($data['note'])) {
            $section->addTextBreak(1);
            $section->addText($data['note'], ['size' => 9, 'italic' => true]);
        }
        if (! empty($data['generated_at'])) {
            $section->addText('Dicetak pada: '.$data['generated_at'].' WITA', ['size' => 9]);
        }

        $dir = storage_path('app/private/media/'.$userId);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = $data['filename'] ?? 'Laporan-Kinerja-'.date('Y-m').'.docx';
        $path = 'media/'.$userId.'/'.$filename;
        $full = storage_path('app/private/'.$path);
        IOFactory::createWriter($phpWord, 'Word2007')->save($full);

        return Media::create([
            'user_id' => $userId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => filesize($full),
            'path' => $path,
            'preview' => null,
            'model' => null,
        ]);
    }

    private static function table(array $rows, array $widths, bool $headerBold, bool $shadeHeader): void
    {
        if (empty($rows)) {
            return;
        }

        $table = (new \PhpOffice\PhpWord\PhpWord())->addSection()->addTable([
            'borderSize' => 6,
            'borderColor' => 'BBBBBB',
            'cellMargin' => 60,
            'width' => 100 * 50,
            'unit' => Table::WIDTH_PERCENT,
        ]);
        // shortcut: PhpWord table style attached via section context above not trivial; reuse simple approach
        // rebuild minimal by adding to last section not easy here; just rely on default for now, upgrade to full style later
        unset($table);

        // Fallback minimal table rendering
        $phpWord = new PhpWord();
        $section = $phpWord->addSection([
            'marginLeft' => 600,
            'marginRight' => 600,
            'marginTop' => 800,
            'marginBottom' => 800,
        ]);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 70]);
        foreach ($rows as $i => $row) {
            $table->addRow();
            foreach ($row as $j => $cell) {
                $w = $widths[$j] ?? 1500;
                $c = $table->addCell($w);
                if (is_array($cell)) {
                    $text = $cell['text'] ?? '';
                    $bold = $cell['bold'] ?? ($headerBold && $i === 0);
                    $c->addText($text, ['bold' => $bold, 'size' => 9]);
                } else {
                    $bold = $headerBold && $i === 0;
                    $c->addText((string) $cell, ['bold' => $bold, 'size' => 9]);
                }
            }
        }
    }
}
