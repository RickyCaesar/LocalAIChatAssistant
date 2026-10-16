<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MarpGenerator
{
    // ponytail: generate PPTX via Marp CLI, one wrapper around a single command.
    public static function generatePptx(string $markdown, ?int $userId, ?int $sessionId = null, ?string $name = null, array $theme = []): Media
    {
        // Pastikan ada frontmatter Marp, lalu paksa tema modern kita walau
        // markdown menulis "theme: default". ponytail: satu tema; override warna
        // per-deck via <style> di markdown.
        $markdown = self::normalize($markdown, $theme);

        $temp = tempnam(sys_get_temp_dir(), 'marp');
        if ($temp === false) {
            throw new \RuntimeException('Tidak bisa membuat file sementara.');
        }
        file_put_contents($temp, $markdown);

        // Disimpan di private/ agar konsisten dengan MediaController (yang melayani
        // berkas dari storage/app/private). ponytail: awalnya salah taruh di public/.
        $rel = 'presentations/'.Str::uuid().'.pptx';
        $out = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }

        // Browser headless dipakai Marp untuk render. CHROME_PATH diarahkan ke
        // Chromium yang dipasang di image.
        $result = Process::env(['CHROME_PATH' => '/usr/bin/chromium'])->run([
            'marp',
            $temp,
            '--theme-set',
            resource_path('marp/notebook.css'),
            '--theme',
            'notebook',
            '--html',
            '--pptx',
            '--allow-local-files',
            '-o',
            $out,
        ]);

        @unlink($temp);

        if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
            throw new ProcessFailedException($result);
        }

        $filename = self::safeName($name).'.pptx';

        return Media::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'size' => filesize($out),
            'path' => $rel,
            'preview' => null,
            'model' => null,
        ]);
    }

    private static function normalize(string $markdown, array $theme = []): string
    {
        $markdown = ltrim($markdown);
        $vars = self::themeVars($theme);

        if ($vars !== '') {
            // Buang <style> bawaan model agar tema pilihan pengguna menang.
            $markdown = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $markdown) ?? $markdown;
        }

        if (! str_starts_with($markdown, '---')) {
            $markdown = "---\nmarp: true\ntheme: notebook\npaginate: true\n---\n\n".$markdown;
        }
        $markdown = preg_replace('/^(\s*)theme:.*$/m', '$1theme: notebook', $markdown, 1) ?? $markdown;

        // Sisipkan palet pilihan tepat sebelum isi (setelah frontmatter).
        if ($vars !== '' && preg_match('/\A(---\n.*?\n---\n)(.*)\z/s', $markdown, $fm)) {
            $markdown = $fm[1]."\n<style>section{".$vars."}</style>\n".$fm[2];
        }

        if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $markdown, $m)) {
            return $markdown;
        }
        [$all, $front, $body] = $m;
        if (preg_match('/^---\s*$/m', $body)) {
            return $markdown;
        }

        // ponytail: model kadang lupa pemisah slide → tanpa ini jadi 1 slide
        // panjang. Pisah di tiap directive `_class`/heading `##`. Upgrade path:
        // andalkan prompt saja bila kepatuhan model sudah bagus.
        return "---\n".$front."\n---\n".self::splitSlides($body);
    }

    // Palet tema dipilih pengguna di UI. Hanya terima warna hex; selain itu diabaikan.
    private static function themeVars(array $theme): string
    {
        $vars = '';
        foreach (['accent', 'accent2', 'bg', 'ink'] as $key) {
            $val = $theme[$key] ?? null;
            if (is_string($val) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                $vars .= '--'.$key.':'.$val.';';
            }
        }

        return $vars;
    }

    private static function splitSlides(string $body): string
    {
        $out = [];
        $seenMarker = false;
        $prevDirective = false;

        foreach (explode("\n", $body) as $line) {
            $isDirective = (bool) preg_match('/^<!--\s*_class:/', $line);
            $isHeading = (bool) preg_match('/^##\s/', $line);

            if (($isDirective || $isHeading) && $seenMarker && ! $prevDirective) {
                $out[] = '';
                $out[] = '---';
                $out[] = '';
            }
            if ($isDirective || $isHeading) {
                $seenMarker = true;
            }

            $out[] = $line;
            $prevDirective = $isDirective;
        }

        return implode("\n", $out);
    }

    private static function safeName(?string $name): string
    {
        $base = pathinfo(trim((string) $name), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9 _-]/', '', $base);

        return $base !== '' ? $base : 'presentasi-'.Str::lower(Str::random(6));
    }
}
