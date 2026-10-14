<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

// Transkripsi audio berat (faster-whisper CPU) dijalankan di container
// queue-worker, bukan di dalam request. Hasilnya ditulis ke row Transcription
// yang di-poll frontend.
class TranscribeAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 3;

    public function __construct(public int $transcriptionId)
    {
    }

    public function handle(): void
    {
        $job = Transcription::find($this->transcriptionId);
        if (! $job || $job->status === 'done') {
            return;
        }

        $job->update(['status' => 'processing', 'error' => null]);

        $audio = storage_path('app/'.$job->path);
        if (! is_file($audio)) {
            $job->update(['status' => 'error', 'error' => 'Berkas audio tidak ditemukan.']);

            return;
        }

        $python = (string) env('STT_PYTHON', '/opt/stt/bin/python');
        $script = base_path('stt/transcribe.py');
        $model = $job->model ?: (string) Setting::get('stt_model', 'small');
        $language = (string) Setting::get('stt_language', 'id');

        $result = Process::timeout((int) Setting::get('ai_timeout', 3600))
            ->run([$python, $script, '--file', $audio, '--model', $model, '--language', $language]);

        @unlink($audio);

        if (! $result->successful()) {
            $job->update([
                'status' => 'error',
                'error' => trim($result->errorOutput()) ?: 'Transkripsi gagal.',
            ]);

            return;
        }

        $data = json_decode($result->output(), true) ?: [];

        $job->update([
            'status' => 'done',
            'text' => (string) ($data['text'] ?? ''),
            'language' => $data['language'] ?? null,
            'duration' => $data['duration'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Transcription::whereKey($this->transcriptionId)->update([
            'status' => 'error',
            'error' => $e->getMessage(),
        ]);
    }
}
