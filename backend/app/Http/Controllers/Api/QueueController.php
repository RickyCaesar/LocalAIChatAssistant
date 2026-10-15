<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunTask;
use App\Models\Setting;
use App\Models\Task;
use App\Support\Eta;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QueueController extends Controller
{
    // Status proses + estimasi selesai (dihitung dari laju progress).
    private function serialize(Task $task, int $position = -1): array
    {
        $eta = $task->status === 'processing'
            ? Eta::seconds($task->started_at?->getTimestamp(), (int) $task->progress)
            : null;

        return [
            'id' => (string) $task->id,
            'type' => $task->type,
            'title' => $task->title,
            'status' => $task->status,
            'progress' => (int) $task->progress,
            'eta' => $eta,
            'duration' => $task->duration,
            'duration_seconds' => $task->duration,
            'position' => $position,
            'result' => $task->result,
            'error' => $task->error,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'finished_at' => optional($task->finished_at)->toIso8601String(),
        ];
    }

    public function index()
    {
        $tasks = Task::where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $position = [];
        foreach ($tasks->whereIn('status', ['queued', 'processing'])->sortBy('id')->values() as $i => $task) {
            $position[(string) $task->id] = $i;
        }

        return response()->json([
            'tasks' => $tasks->map(fn (Task $t) => $this->serialize($t, $position[(string) $t->id] ?? -1))->values(),
        ]);
    }

    public function show(string $id)
    {
        $task = Task::where('user_id', auth()->id())->findOrFail($id);

        return response()->json($this->serialize($task));
    }

    // Unggah audio lalu taruh di antrean STT.
    public function storeTranscribe(Request $request)
    {
        $maxKb = max(1, (int) Setting::get('max_upload_mb', 20)) * 1024;
        $request->validate([
            'audio' => 'required|file|mimetypes:audio/*,video/mp4,application/ogg|max:'.$maxKb,
        ]);

        $file = $request->file('audio');
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension() ?: 'mp3')) ?: 'mp3';
        $name = Str::uuid().'.'.$ext;
        $dir = storage_path('app/transcribe');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->move($dir, $name);

        $task = Task::create([
            'user_id' => auth()->id(),
            'type' => 'transcribe',
            'title' => $file->getClientOriginalName(),
            'status' => 'queued',
            'payload' => [
                'path' => 'transcribe/'.$name,
                'model' => (string) Setting::get('stt_model', 'small'),
                'language' => (string) Setting::get('stt_language', 'id'),
            ],
        ]);

        RunTask::dispatch($task->id);

        return response()->json($this->serialize($task, 0), 202);
    }

    // Unggah video lalu taruh di antrean ekstraksi MP3.
    public function storeConvert(Request $request)
    {
        $maxKb = max(1, (int) Setting::get('max_upload_mb', 20)) * 1024;
        $request->validate([
            'video' => 'required|file|max:'.$maxKb,
        ]);

        $file = $request->file('video');
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension() ?: 'mp4')) ?: 'mp4';
        $name = Str::uuid().'.'.$ext;
        $dir = storage_path('app/convert');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->move($dir, $name);

        $task = Task::create([
            'user_id' => auth()->id(),
            'type' => 'convert',
            'title' => $file->getClientOriginalName(),
            'status' => 'queued',
            'payload' => [
                'path' => 'convert/'.$name,
                'filename' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ],
        ]);

        RunTask::dispatch($task->id);

        return response()->json($this->serialize($task), 202);
    }

    // Susun notulensi dari transkrip lewat AI di antrean.
    public function storeNotulensi(Request $request)
    {
        $data = $request->validate([
            'source' => 'required|string|max:200000',
        ]);

        $title = trim(preg_replace('/\s+/', ' ', mb_substr($data['source'], 0, 50)) ?? '');

        $task = Task::create([
            'user_id' => auth()->id(),
            'type' => 'notulensi',
            'title' => $title !== '' ? $title : 'Notulensi',
            'status' => 'queued',
            'payload' => ['source' => $data['source']],
        ]);

        RunTask::dispatch($task->id);

        return response()->json($this->serialize($task), 202);
    }

    public function destroy(string $id)
    {
        $task = Task::where('user_id', auth()->id())->findOrFail($id);

        // ponytail: tugas yang sedang diproses tak bisa dihentikan paksa (worker
        // sedang di tengah proses). Selain itu boleh dihapus dari antrean/riwayat.
        if ($task->status === 'processing') {
            return response()->json(['error' => 'Tugas sedang diproses, tidak bisa dibatalkan.'], 409);
        }

        if ($task->payload['path'] ?? null) {
            @unlink(storage_path('app/'.$task->payload['path']));
        }

        if ($mediaId = $task->result['media_id'] ?? null) {
            if ($media = \App\Models\Media::find($mediaId)) {
                @unlink(storage_path('app/private/'.$media->path));
                $media->delete();
            }
        }

        $task->delete();

        return response()->json(['ok' => true]);
    }
}
