<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

// Text-to-speech lokal memakai Piper (CPU). Model default di-bake ke image di
// /opt/tts/voices; model tambahan cukup ditaruh di storage/app/tts_models
// (atau folder TTS_MODEL_PATH).
class TTSService
{
    public const DEFAULT_VOICE = 'id_ID-news_tts-medium';

    // Folder model: TTS_MODEL_PATH (opsional) + folder bawaan + folder user.
    public static function dirs(): array
    {
        return array_values(array_unique(array_filter([
            rtrim((string) env('TTS_MODEL_PATH', ''), '/'),
            '/opt/tts/voices',
            storage_path('app/tts_models'),
        ])));
    }

    public static function modelPath(string $voice): ?string
    {
        // Buang karakter aneh agar `voice` tak bisa keluar dari folder model.
        $voice = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $voice);
        if ($voice === '') {
            return null;
        }

        foreach (self::dirs() as $dir) {
            $onnx = $dir.'/'.$voice.'.onnx';
            if (is_file($onnx) && is_file($onnx.'.json')) {
                return $onnx;
            }
        }

        return null;
    }

    public static function voices(): array
    {
        $out = [];
        foreach (self::dirs() as $dir) {
            foreach (glob($dir.'/*.onnx') ?: [] as $onnx) {
                $id = basename($onnx, '.onnx');
                if (isset($out[$id]) || ! is_file($onnx.'.json')) {
                    continue;
                }
                $out[$id] = ['id' => $id, 'language' => explode('_', $id)[0] ?: 'und'];
            }
        }
        ksort($out);

        return array_values($out);
    }

    public static function defaultVoice(): string
    {
        if (self::modelPath(self::DEFAULT_VOICE)) {
            return self::DEFAULT_VOICE;
        }

        return (string) (self::voices()[0]['id'] ?? '');
    }

    // Menghasilkan (atau memakai ulang dari cache) WAV untuk `text`.
    // Kecepatan 1.0 = normal; Piper memakai length_scale = 1/kecepatan.
    public static function synthesize(string $text, string $voice, float $speed): string
    {
        $voice = $voice !== '' ? $voice : self::defaultVoice();
        $model = self::modelPath($voice) ?: self::modelPath(self::defaultVoice());
        if ($model === null) {
            throw new \RuntimeException('Model suara tidak ditemukan. Lihat README untuk menambah model Piper.');
        }

        $voice = basename($model, '.onnx');
        $speed = min(2.0, max(0.5, $speed));
        $dir = storage_path('app/private/tts');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $wav = $dir.'/'.sha1($voice.'|'.$speed.'|'.$text).'.wav';
        if (is_file($wav)) {
            return $wav;
        }

        self::prune($dir);

        $result = Process::timeout(120)
            ->input(self::plainText($text))
            ->run([
                (string) env('TTS_PIPER', '/opt/stt/bin/piper'),
                '-m', $model,
                '-f', $wav,
                '--length-scale', (string) (1 / $speed),
            ]);

        if (! $result->successful() || ! is_file($wav) || filesize($wav) === 0) {
            @unlink($wav);

            throw new \RuntimeException('TTS gagal: '.(trim($result->errorOutput()) ?: 'tidak ada audio yang dihasilkan.'));
        }

        return $wav;
    }

    // Markdown di balasan AI jadi jelek kalau dibacakan apa adanya.
    // ponytail: regex kasar (bukan parser Markdown); cukup untuk balasan chat.
    private static function plainText(string $text): string
    {
        $t = preg_replace('/```.*?```/s', ' ', $text);
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $t);
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t);
        $t = preg_replace('/[`*_#>~|]/', ' ', (string) $t);
        $t = preg_replace('/^\s*[-+]\s+/m', '', (string) $t);
        $t = preg_replace('/\s+/', ' ', (string) $t);

        return trim((string) $t);
    }

    // ponytail: cache dibersihkan saat ada sintesis baru, umur >24 jam.
    private static function prune(string $dir): void
    {
        foreach (glob($dir.'/*.wav') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
