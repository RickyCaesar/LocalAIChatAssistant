<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Setting;
use App\Support\FilePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
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

    // Konversi berkas ke PDF pakai LibreOffice headless. ponytail: proses
    // sinkron (~beberapa detik/doc), blokir itu wajar untuk pemakaian lokal.
    public function toPdf(string $id)
    {
        $media = Media::where('user_id', auth()->id())->findOrFail($id);
        $src = storage_path('app/private/'.$media->path);

        if (! is_file($src)) {
            return response()->json(['error' => 'Berkas tidak ditemukan.'], 404);
        }
        if ($media->mime === 'application/pdf') {
            return response()->json($this->serialize($media));
        }

        $outDir = storage_path('app/private/tmp-convert');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        $home = sys_get_temp_dir().'/lo-'.Str::uuid();
        $process = Process::timeout(180)->run([
            'soffice', '--headless', '--norestore',
            '-env:UserInstallation=file://'.$home,
            '--convert-to', 'pdf', '--outdir', $outDir, $src,
        ], null, ['HOME' => $home]);

        $pdf = $outDir.'/'.pathinfo($src, PATHINFO_FILENAME).'.pdf';
        if (! is_file($pdf)) {
            return response()->json([
                'error' => 'Konversi ke PDF gagal.',
                'detail' => trim($process->errorOutput() ?: $process->output()),
            ], 422);
        }

        $rel = 'media/'.auth()->id().'/'.Str::uuid().'.pdf';
        $dest = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        rename($pdf, $dest);

        $pdfMedia = Media::create([
            'user_id' => auth()->id(),
            'session_id' => $media->session_id,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => pathinfo($media->filename, PATHINFO_FILENAME).'.pdf',
            'mime' => 'application/pdf',
            'size' => filesize($dest),
            'path' => $rel,
            'preview' => FilePreview::extract($dest),
            'model' => $media->model,
        ]);

        return response()->json($this->serialize($pdfMedia), 201);
    }

    public function show(string $id)
    {
        $media = Media::where('user_id', auth()->id())->findOrFail($id);

        $mime = strtolower((string) $media->mime);
        $inline = in_array($mime, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif',
            'application/pdf',
            'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm',
            'video/mp4', 'video/webm', 'video/ogg',
        ], true) && ! request()->boolean('download');

        $headers = [
            'Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (! $inline) {
            $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        $response = response()->file(storage_path('app/private/'.$media->path), $headers);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $media->filename);

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