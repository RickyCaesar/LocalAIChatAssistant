<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MarpGenerator
{
    // ponytail: generate PPTX via Marp CLI, one wrapper around a single command.
    public static function generatePptx(string $markdown, ?int $userId, ?int $sessionId = null): Media
    {
        $temp = tempnam(sys_get_temp_dir(), 'marp');
        if ($temp === false) {
            throw new \RuntimeException('Tidak bisa membuat file sementara.');
        }
        file_put_contents($temp, $markdown);

        $dir = storage_path('app/public/presentations');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = Str::uuid().'.pptx';
        $out = $dir.'/'.$filename;

        // Using Marp CLI. ponytail: chrome installed inside marp-cli deps - assume available.
        $result = Process::run([
            'marp',
            $temp,
            '--pptx',
            '-o',
            $out,
        ]);

        @unlink($temp);

        if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
            throw new ProcessFailedException($result);
        }

        $media = Media::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => 'presentasi-'.$filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'size' => filesize($out),
            'path' => 'public/presentations/'.$filename,
            'preview' => null,
            'model' => null,
        ]);

        return $media;
    }
}
