<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\Setting;
use App\Models\Task;
use App\Support\MarpGenerator;
use App\Support\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// Semua proses berat (convert video->mp3, transkripsi, notulensi AI) berjalan
// lewat satu job ini di container queue-worker. Satu worker = satu antrean,
// jadi urutan & progres bisa ditampilkan apa adanya ke user.
class RunTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    // ponytail: tanpa retry - mengulang STT/konversi hanya membuang menit CPU
    // dan berkas sumber sudah dihapus setelah percobaan pertama.
    public int $tries = 1;

    private const NOTULENSI_PROMPT =
        'Kamu adalah notulis rapat profesional. Dari transkrip/catatan berikut, buat notulensi rapi dalam bahasa Indonesia dengan bagian: Ringkasan, Poin Penting, Keputusan, dan Action Items. Jangan mengarang di luar isi.';

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = Task::find($this->taskId);
        if (! $task || in_array($task->status, ['done', 'error'], true)) {
            return;
        }

        $task->update(['status' => 'processing', 'started_at' => now(), 'progress' => 1, 'error' => null]);

        try {
            match ($task->type) {
                'convert' => $this->runConvert($task),
                'transcribe' => $this->runTranscribe($task),
                'notulensi' => $this->runNotulensi($task),
                'pptx' => $this->runPptx($task),
                default => throw new \RuntimeException('Jenis tugas tidak dikenal: '.$task->type),
            };
        } catch (\Throwable $e) {
            $task->update(['status' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()]);
        }

        // Satu panggilan untuk sukses & gagal; status task sudah final di sini.
        NotificationService::taskFinished($task);
    }

    public function failed(\Throwable $e): void
    {
        $task = Task::find($this->taskId);
        if (! $task) {
            return;
        }

        $task->update([
            'status' => 'error',
            'error' => $e->getMessage(),
            'finished_at' => now(),
            'duration_seconds' => $this->getTaskDuration($this->taskId),
        ]);

        NotificationService::taskFinished($task);
    }

    private function getTaskDuration(int $id): ?int
    {
        $task = Task::find($id);
        if ($task && $task->started_at) {
            return max(0, now()->diffInSeconds($task->started_at, false));
        }
        return null;
    }

    private function runConvert(Task $task): void
    {
        $src = storage_path('app/'.(string) ($task->payload['path'] ?? ''));
        if (! is_file($src)) {
            throw new \RuntimeException('Berkas video tidak ditemukan.');
        }

        $rel = 'media/'.$task->user_id.'/'.Str::uuid().'.mp3';
        $dest = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }

        $this->runWithProgress($task, [
            (string) env('STT_PYTHON', '/opt/stt/bin/python'),
            base_path('stt/toaudio.py'),
            '--file', $src,
            '--out', $dest,
        ], 300);

        @unlink($src);

        if (! is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);

            throw new \RuntimeException('Konversi ke MP3 gagal.');
        }

        $media = Media::create([
            'user_id' => $task->user_id,
            'session_id' => null,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => (string) ($task->payload['filename'] ?? 'audio').'.mp3',
            'mime' => 'audio/mpeg',
            'size' => filesize($dest),
            'path' => $rel,
            'preview' => null,
            'model' => null,
        ]);

        $task->update([
            'status' => 'done',
            'progress' => 100,
            'finished_at' => now(),
            'duration_seconds' => $task->started_at ? max(0, now()->diffInSeconds($task->started_at, false)) : null,
            'result' => [
                'media_id' => (string) $media->id,
                'url' => '/api/media/'.$media->id,
                'filename' => $media->filename,
            ],
        ]);
    }

    private function runTranscribe(Task $task): void
    {
        $audio = storage_path('app/'.(string) ($task->payload['path'] ?? ''));
        if (! is_file($audio)) {
            throw new \RuntimeException('Berkas audio tidak ditemukan.');
        }

        $result = $this->runWithProgress($task, [
            (string) env('STT_PYTHON', '/opt/stt/bin/python'),
            base_path('stt/transcribe.py'),
            '--file', $audio,
            '--model', (string) ($task->payload['model'] ?? Setting::get('stt_model', 'small')),
            '--language', (string) ($task->payload['language'] ?? Setting::get('stt_language', 'id')),
        ], (int) Setting::get('ai_timeout', 3600));

        @unlink($audio);

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Transkripsi gagal.');
        }

        $data = json_decode($result->output(), true) ?: [];

        $task->update([
            'status' => 'done',
            'progress' => 100,
            'finished_at' => now(),
            'duration_seconds' => $task->started_at ? max(0, now()->diffInSeconds($task->started_at, false)) : null,
            'result' => [
                'text' => (string) ($data['text'] ?? ''),
                'language' => $data['language'] ?? null,
                'duration' => $data['duration'] ?? null,
            ],
        ]);
    }

    private function runPptx(Task $task): void
    {
        $payload = $task->payload ?? [];
        $markdown = (string) ($payload['markdown'] ?? '');
        if (trim($markdown) === '') {
            throw new \RuntimeException('Markdown presentasi kosong.');
        }

        $task->update(['progress' => 20]);

        $media = MarpGenerator::generatePptx(
            $markdown,
            $task->user_id,
            null,
            (string) ($payload['filename'] ?? ''),
            is_array($payload['theme'] ?? null) ? $payload['theme'] : [],
        );

        $task->update([
            'status' => 'done',
            'progress' => 100,
            'finished_at' => now(),
            'duration_seconds' => $task->started_at ? max(0, now()->diffInSeconds($task->started_at, false)) : null,
            'result' => [
                'media_id' => (string) $media->id,
                'url' => '/api/media/'.$media->id,
                'filename' => $media->filename,
                'size' => (int) $media->size,
            ],
        ]);
    }

    private function runNotulensi(Task $task): void
    {
        $source = trim((string) ($task->payload['source'] ?? ''));
        if ($source === '') {
            throw new \RuntimeException('Isi transkrip kosong.');
        }

        $url = rtrim((string) env('AI_API_URL', 'http://host.docker.internal:1234/v1/chat/completions'), '/');
        $model = Setting::get('ai_model') ?: (string) env('AI_MODEL', 'hermes-agent');

        $http = Http::timeout((int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)))
            ->acceptJson()->asJson()->withOptions(['stream' => true]);
        if ($key = (string) env('AI_API_KEY')) {
            $http = $http->withToken($key);
        }

        $response = $http->post($url, [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::NOTULENSI_PROMPT],
                ['role' => 'user', 'content' => $source],
            ],
            'temperature' => (float) env('AI_TEMPERATURE', 0.7),
            'stream' => true,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('AI menolak permintaan (HTTP '.$response->status().').');
        }

        $type = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($type, 'text/event-stream')) {
            $text = (string) ($response->json('choices.0.message.content') ?? '');
        } else {
            $text = $this->runNotulensiStream($task, $response->toPsrResponse()->getBody(), $source);
        }

        if (trim($text) === '') {
            throw new \RuntimeException('AI tidak mengembalikan notulensi.');
        }

        $task->update([
            'status' => 'done',
            'progress' => 100,
            'finished_at' => now(),
            'duration_seconds' => $task->started_at ? max(0, now()->diffInSeconds($task->started_at, false)) : null,
            'result' => ['text' => $text],
        ]);
    }

    // Baca SSE dari gateway sambil memperbarui progres berbasis waktu. ponytail:
    // throughput ~60 char/detik adalah tebakan kasar untuk model lokal; kalau
    // ETA sering meleset, kalibrasi konstanta ini.
    private function runNotulensiStream(Task $task, $body, string $source): string
    {
        $estimate = max(20.0, mb_strlen($source) / 60.0);
        $start = microtime(true);
        $accum = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '' || $chunk === false) {
                if ($body->eof()) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $accum .= $chunk;
            $pct = (int) min(99, (microtime(true) - $start) / $estimate * 100);
            if ($pct > (int) $task->progress) {
                $task->update(['progress' => max(1, $pct)]);
            }
        }

        $text = '';
        if (preg_match_all('/^data:\s*(\{.*\})\s*$/m', $accum, $m)) {
            foreach ($m[1] as $line) {
                $data = json_decode($line, true);
                if (is_array($data)) {
                    $text .= (string) ($data['choices'][0]['delta']['content'] ?? '');
                }
            }
        }

        return $text;
    }

    // Jalankan proses lalu update progress dari baris "PROGRESS <pct>" di stderr.
    private function runWithProgress(Task $task, array $command, int $timeout)
    {
        $carry = '';

        return Process::timeout($timeout)->run($command, function (string $type, string $buffer) use ($task, &$carry) {
            if ($type !== 'err') {
                return;
            }
            $carry .= $buffer;
            while (($pos = strpos($carry, "\n")) !== false) {
                $line = trim(substr($carry, 0, $pos));
                $carry = substr($carry, $pos + 1);
                if (preg_match('/^PROGRESS\s+(\d+)/', $line, $m)) {
                    $pct = max(1, min(99, (int) $m[1]));
                    if ($pct !== (int) $task->progress) {
                        $task->update(['progress' => $pct]);
                    }
                }
            }
        });
    }
}
