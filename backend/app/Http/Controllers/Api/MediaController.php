<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Setting;
use App\Support\FilePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    private function serialize(Media $media): array
    {
        return [
            'id' => (string) $media->id,
            'filename' => $media->filename,
            'role' => $media->role,
            'kind' => $media->kind,
            'mime' => $media->mime,
            'size' => (int) $media->size,
            'url' => '/api/media/'.$media->id,
            'preview' => $media->preview,
            'session_id' => $media->session_id ? (string) $media->session_id : null,
            'session_title' => $media->session_id ? ($media->session->title ?? null) : null,
            'model' => $media->model,
            'created_at' => $media->created_at ? $media->created_at->toIso8601String() : null,
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|file|max:'.((int) Setting::get('max_upload_mb', 20) * 1024),
        ]);

        $file = $request->file('image');
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $path = $file->storeAs('media/'.auth()->id(), Str::uuid().'.'.$ext, 'local');

        $full = storage_path('app/private/'.$path);
        $mime = FilePreview::mime($full, $file->getMimeType() ?: 'application/octet-stream');
        $isImage = str_starts_with($mime, 'image/');

        $media = Media::create([
            'user_id' => auth()->id(),
            'role' => 'user',
            'kind' => $isImage ? 'image' : 'file',
            'filename' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'path' => $path,
            'preview' => FilePreview::extract($full),
            'model' => null,
        ]);

        return response()->json($this->serialize($media), 201);
    }

    public function index(Request $request)
    {
        $query = Media::where('user_id', auth()->id())->with('session:id,title');

        if (in_array($request->query('role'), ['user', 'assistant'], true)) {
            $query->where('role', $request->query('role'));
        }
        if ($request->filled('session_id')) {
            $query->where('session_id', $request->query('session_id'));
        }

        $media = $query->orderByDesc('created_at')->get()->map(fn ($m) => $this->serialize($m));

        return response()->json(['media' => $media]);
    }

    public function show(string $id)
    {
        $media = Media::where('user_id', auth()->id())->findOrFail($id);

        $response = response()->file(storage_path('app/private/'.$media->path), ['Content-Type' => $media->mime]);
        $response->setContentDisposition(
            request()->boolean('download') ? 'attachment' : 'inline',
            $media->filename
        );

        return $response;
    }

    public function destroy(string $id)
    {
        $media = Media::where('user_id', auth()->id())->findOrFail($id);

        $full = storage_path('app/private/'.$media->path);
        if (is_file($full)) {
            @unlink($full);
        }
        $media->delete();

        return response()->json(['ok' => true]);
    }
}