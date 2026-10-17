<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\TTSService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class TTSController extends Controller
{
    public function voices()
    {
        return response()->json([
            'voices' => TTSService::voices(),
            'default' => (string) Setting::get('tts_voice', TTSService::defaultVoice()),
        ]);
    }

    // Mengembalikan berkas WAV. Frontend mengambilnya sebagai blob (fetch
    // dengan Bearer token) lalu memutarnya — tidak lewat <audio src>.
    public function speak(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|max:4000',
            'voice' => 'nullable|string|max:100',
            'speed' => 'nullable|numeric|min:0.5|max:2',
        ]);

        $voice = (string) ($data['voice'] ?? Setting::get('tts_voice', ''));
        $speed = (float) ($data['speed'] ?? Setting::get('tts_speed', 1));

        try {
            $wav = TTSService::synthesize($data['text'], $voice, $speed);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->file($wav, ['Content-Type' => 'audio/wav']);
    }

    // Transkripsi sinkron untuk voice mode (tanpa antrean — jeda worker +
    // polling terlalu lama untuk percakapan bergantian).
    public function stt(Request $request)
    {
        $request->validate(['audio' => 'required|file|max:25600']);
        $file = $request->file('audio');

        $result = Process::timeout((int) Setting::get('ai_timeout', 600))->run([
            (string) env('STT_PYTHON', '/opt/stt/bin/python'),
            base_path('stt/transcribe.py'),
            '--file', $file->getRealPath(),
            '--model', (string) Setting::get('stt_model', 'small'),
            '--language', (string) Setting::get('stt_language', 'id'),
        ]);

        if (! $result->successful()) {
            return response()->json(['error' => trim($result->errorOutput()) ?: 'Transkripsi gagal.'], 500);
        }

        $data = json_decode($result->output(), true) ?: [];

        return response()->json([
            'text' => (string) ($data['text'] ?? ''),
            'language' => $data['language'] ?? null,
        ]);
    }
}
