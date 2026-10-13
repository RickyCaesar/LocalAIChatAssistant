<?php

namespace App\Support;

class FilePreview
{
    public static function mime(string $path, string $fallback = 'application/octet-stream'): string
    {
        $byExt = [
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'pdf' => 'application/pdf',
        ][strtolower((string) pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        return $byExt ?? ((string) (mime_content_type($path) ?: $fallback));
    }

    public static function extract(string $path): ?string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $text = null;

        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff', 'webp'], true)) {
            $text = self::ocr($path);
        } elseif (in_array($ext, ['pptx', 'docx', 'xlsx'], true)) {
            try {
                $zip = new \ZipArchive();
                if ($zip->open($path) !== true) {
                    return null;
                }
                if ($ext === 'pptx') {
                    $slides = [];
                    for ($i = 1; $i <= 300; $i++) {
                        $xml = $zip->getFromName(sprintf('ppt/slides/slide%d.xml', $i));
                        if ($xml === false) {
                            break;
                        }
                        $marked = preg_replace('/<a:t[^>]*>([^<]*)<\/a:t>/', "\n$1", $xml) ?? '';
                        $texts = preg_split('/\R/', trim(strip_tags($marked))) ?: [];
                        $texts = array_values(array_filter(array_map('trim', $texts), fn ($t) => $t !== ''));
                        if ($texts !== []) {
                            $slides[] = 'Slide '.$i.': '.implode(' | ', $texts);
                            continue;
                        }
                        // Slide tanpa lapisan teks (murni gambar) -> OCR gambarnya.
                        foreach (self::slideImages($zip, $i) as $img) {
                            $ocr = self::ocrData($img);
                            if ($ocr !== '') {
                                $slides[] = 'Slide '.$i.' (gambar): '.$ocr;
                                break;
                            }
                        }
                    }
                    $text = implode("\n", $slides);
                } elseif ($ext === 'docx') {
                    $xml = $zip->getFromName('word/document.xml');
                    if ($xml !== false) {
                        $text = trim(strip_tags(str_replace(['<w:tab/>', '</w:p>'], [' | ', "\n"], $xml)));
                    }
                } else {
                    $sx = $zip->getFromName('xl/sharedStrings.xml');
                    if ($sx !== false) {
                        preg_match_all('/<t(?:\s[^>]*)?>([^<]*)<\/t>/', $sx, $ms);
                        $text = trim(implode(' | ', $ms[1] ?? []));
                    }
                }
                $zip->close();
            } catch (\Throwable) {
                return null;
            }
        } elseif (in_array($ext, ['txt', 'md', 'csv', 'json', 'log', 'rtf'], true)) {
            $text = (string) file_get_contents($path, false, null, 0, 8000);
        } elseif ($ext === 'pdf') {
            $text = self::pdfText($path);
        }

        $text = (string) $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, 12000) : null;
    }

    // Gambar-gambar yang dirujuk sebuah slide (ppt/media/...), dalam urutan rel.
    private static function slideImages(\ZipArchive $zip, int $slide): array
    {
        $rels = $zip->getFromName(sprintf('ppt/slides/_rels/slide%d.xml.rels', $slide));
        if ($rels === false) {
            return [];
        }
        preg_match_all('/Target="([^"]+)"/', $rels, $m);
        $images = [];
        foreach ($m[1] ?? [] as $target) {
            if (! preg_match('/media\/[^"\']+\.(png|jpe?g|gif|bmp|tiff?|webp)$/i', $target)) {
                continue;
            }
            $name = 'ppt/'.ltrim(preg_replace('#^(\.\./)+#', '', $target) ?? $target, '/');
            $data = $zip->getFromName($name);
            if ($data !== false) {
                $images[] = $data;
            }
        }

        return $images;
    }

    private static function ocrData(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ocr');
        if ($tmp === false) {
            return '';
        }
        file_put_contents($tmp, $bytes);

        return self::ocr($tmp);
    }

    private static function ocr(string $imagePath): string
    {
        static $available = null;
        $available ??= trim((string) @shell_exec('command -v tesseract 2>/dev/null')) !== '';
        if (! $available) {
            return '';
        }
        // ponytail: OCR sinkron saat upload; untuk deck besar (banyak slide) bisa
        // lambat. Pindah ke queue bila dirasa mengganggu.
        $out = @shell_exec('tesseract '.escapeshellarg($imagePath).' stdout -l ind+eng 2>/dev/null');

        return preg_replace('/[ \t]+/', ' ', (string) $out) ?? (string) $out;
    }

    private static function pdfText(string $path): string
    {
        static $available = null;
        $available ??= trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) !== '';
        if (! $available) {
            return '';
        }

        return (string) @shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
    }
}