<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeAudio;
use App\Models\Setting;
use App\Models\Transcription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TranscribeController extends Controller
{
    private function serialize(Transcription $t): array
    {
        return [
            'id' => (string) $t->id,
            'status' => $t->status,
            'filename' => $t->filename,
            'text' => $t->text,
            'language' => $t->language,
            'duration' => $t->duration,
            'model' => $t->model,
            'error' => $t->error,
        ];
    }

    // Terima unggahan lalu taruh job di antrean. Proses STT (berat) berjalan
    // di container queue-worker; client memantau lewat show().
    public function store(Request $request)
    {
        $maxKb = max(1, (int) Setting::get('max_upload_mb', 20)) * 1024;

        $request->validate([
            'audio' => 'required|file|mimetypes:audio/*,video/mp4,application/ogg|max:'.$maxKb,
        ]);

        $file = $request->file('audio');
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension() ?: 'mp3')) ?: 'mp3';

        $dir = storage_path('app/transcribe');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = Str::uuid().'.'.$ext;
        $file->move($dir, $name);

        $job = Transcription::create([
            'user_id' => auth()->id(),
            'status' => 'pending',
            'filename' => $file->getClientOriginalName(),
            'path' => 'transcribe/'.$name,
            'model' => (string) Setting::get('stt_model', 'small'),
        ]);

        TranscribeAudio::dispatch($job->id);

        return response()->json($this->serialize($job), 202);
    }

    public function show(string $id)
    {
        $job = Transcription::where('user_id', auth()->id())->findOrFail($id);

        return response()->json($this->serialize($job));
    }
}
