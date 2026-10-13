<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class TranscribeController extends Controller
{
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
        $path = $dir.'/'.$name;

        $model = (string) Setting::get('stt_model', 'small');
        $language = (string) Setting::get('stt_language', 'id');
        $python = (string) env('STT_PYTHON', '/opt/stt/bin/python');
        $script = base_path('stt/transcribe.py');

        $result = Process::timeout((int) Setting::get('ai_timeout', 3600))
            ->run([$python, $script, '--file', $path, '--model', $model, '--language', $language]);

        @unlink($path);

        if (! $result->successful()) {
            return response()->json([
                'detail' => 'Transkripsi gagal. '.trim($result->errorOutput()),
            ], 500);
        }

        $data = json_decode($result->output(), true) ?: [];

        return response()->json([
            'text' => $data['text'] ?? '',
            'language' => $data['language'] ?? null,
            'duration' => $data['duration'] ?? null,
            'model' => $data['model'] ?? $model,
        ]);
    }
}
