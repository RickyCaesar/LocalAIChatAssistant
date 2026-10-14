<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\ChatSession;
use App\Models\Media;
use App\Models\Setting;
use App\Support\ActionRunner;
use App\Support\FilePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    private function scrubMediaRefs(string $text): string
    {
        $text = preg_replace('/\[([^\]]*)\]\(\/api\/media\/\d+[^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('#\s*/api/media/\d+#', '', $text) ?? $text;

        return $text;
    }

    private function contentFor(array $turn): array|string
    {
        $text = $this->scrubMediaRefs((string) ($turn['content'] ?? ''));
        $ids = $turn['image_ids'] ?? [];

        if (empty($ids)) {
            return $text;
        }

        $parts = [['type' => 'text', 'text' => $text]];
        $filesText = '';
        foreach (Media::where('user_id', auth()->id())->whereIn('id', $ids)->get() as $item) {
            $full = storage_path('app/private/'.$item->path);
            if (! is_file($full)) {
                continue;
            }
            if (str_starts_with((string) $item->mime, 'image/')) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$item->mime.';base64,'.base64_encode((string) file_get_contents($full))],
                ];
            } else {
                $body = trim((string) $item->preview);
                $filesText .= "\n\n[Lampiran: ".$item->filename."]".($body !== '' ? "\n".$body : '');
            }
        }
        if ($filesText !== '') {
            $parts[0]['text'] = $text.$filesText;
        }
        if (count($parts) <= 1) {
            return $parts[0]['text'];
        }

        return $parts;
    }

    private function linkMedia(array $ids, ?int $sessionId): void
    {
        if (empty($ids) || ! $sessionId) {
            return;
        }
        $ownsSession = ChatSession::where('user_id', auth()->id())->whereKey($sessionId)->exists();
        if (! $ownsSession) {
            return;
        }
        Media::where('user_id', auth()->id())->whereIn('id', $ids)->update(['session_id' => $sessionId]);
    }

    private function materializeDataUrl(string $dataUrl, ?int $sessionId): ?string
    {
        try {
            $meta = explode(';', $dataUrl, 2);
            $mime = strtolower((string) $meta[0]);
            if (! str_starts_with($mime, 'data:image/')) {
                return null;
            }
            $bytes = base64_decode((string) $meta[1], true);
            if ($bytes === false || $bytes === '') {
                return null;
            }
            $ext = match ($mime) {
                'data:image/jpeg' => 'jpg',
                'data:image/gif' => 'gif',
                'data:image/webp' => 'webp',
                default => 'png',
            };
            $path = 'media/'.auth()->id().'/'.Str::uuid().'.'.$ext;
            $dir = dirname(storage_path('app/private/'.$path));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents(storage_path('app/private/'.$path), $bytes);

            $media = Media::create([
                'user_id' => auth()->id(),
                'session_id' => $sessionId,
                'role' => 'assistant',
                'kind' => 'image',
                'filename' => 'output.'.$ext,
                'mime' => explode('/', $mime, 2)[1] ?? 'png',
                'size' => strlen($bytes),
                'path' => $path,
                'model' => Setting::get('ai_model', (string) env('AI_MODEL', 'hermes-agent')),
            ]);

            return '/api/media/'.$media->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveReplyImages(string $reply, ?int $sessionId): string
    {
        return preg_replace_callback(
            '/!\[([^\]]*)\]\((data:image\/[^;)]+;base64,[^)]+)\)/i',
            function ($m) use ($sessionId) {
                $path = $this->materializeDataUrl($m[2], $sessionId);

                return $path ? '![alt]('.$path.')' : $m[0];
            },
            $reply
        );
    }

    // Impor file yang dibuat/diproses Hermes (dirujuk sebagai MEDIA: <path>
    // atau path absolut) dari folder output bersama ke penyimpanan media.
    private function importFileRefs(string $reply, ?int $sessionId): string
    {
        $reply = preg_replace_callback(
            '/(?:MEDIA:\s*)?([`"\']?)((\/[^\s`"\',;)]+\.\w+))\1/',
            function ($m) use ($sessionId) {
                $media = $this->importHostFile($m[2], $sessionId);
                if (! $media) {
                    return $m[0];
                }
                $url = '/api/media/'.$media->id;

                return str_starts_with($media->mime, 'image/')
                    ? '![alt]('.$url.')'
                    : '['.$media->filename.']('.$url.')';
            },
            $reply
        );

        // Guard: buang link /api/media/N yang bukan milik user (mis. dikarang
        // model), agar tidak menghasilkan tombol menuju 404.
        $reply = preg_replace_callback(
            '/\[([^\]]*)\]\(\/api\/media\/(\d+)[^)]*\)/',
            function ($m) {
                return Media::where('user_id', auth()->id())->whereKey((int) $m[2])->exists() ? $m[0] : $m[1];
            },
            $reply
        );

        return $reply;
    }

    private function importHostFile(string $path, ?int $sessionId): ?Media
    {
        $containerRoot = storage_path('app/private');
        $hostRoot = rtrim((string) env('HERMES_OUTPUT_HOST', '/home/ROG/MyAssistant/backend/storage/app/private'), '/');

        $real = $path;
        $readable = str_starts_with($path, '/home/ROG/');
        if ($hostRoot !== '' && str_starts_with($path, $hostRoot.'/')) {
            $real = $containerRoot.'/'.substr($path, strlen($hostRoot) + 1);
            $readable = true;
        }
        if (! $readable || ! is_file($real)) {
            return null;
        }
        if (filesize($real) > 25 * 1024 * 1024) {
            return null;
        }

        $bytes = (string) file_get_contents($real);
        if ($bytes === '' || $bytes === false) {
            return null;
        }

        $mime = FilePreview::mime($real);
        $isImage = str_starts_with($mime, 'image/');
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) ?: ($isImage ? 'png' : 'bin');

        $rel = 'media/'.auth()->id().'/'.Str::uuid().'.'.$ext;
        $full = storage_path('app/private/'.$rel);
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($full, $bytes);

        return Media::create([
            'user_id' => auth()->id(),
            'session_id' => $sessionId,
            'role' => 'assistant',
            'kind' => $isImage ? 'image' : 'file',
            'filename' => pathinfo($path, PATHINFO_BASENAME),
            'mime' => $mime,
            'size' => strlen($bytes),
            'path' => $rel,
            'preview' => FilePreview::extract($real),
            'model' => Setting::get('ai_model', (string) env('AI_MODEL', 'hermes-agent')),
        ]);
    }

    private function respondBuffered($response, string $model)
    {
        if ($response->failed()) {
            ChatLog::create(['user_id' => auth()->id(), 'model' => $model, 'status' => 'error']);
            $detail = $response->json('error.message') ?? $response->body();

            $sentKey = (string) env('AI_API_KEY');
            Log::warning('AI request rejected', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 1000),
                'auth' => $sentKey === '' ? 'none' : 'len='.strlen($sentKey).' end='.substr($sentKey, -4),
            ]);

            return response()->json([
                'error' => 'The local AI server returned an error.',
                'status' => $response->status(),
                'detail' => $detail,
            ], 502);
        }

        $data = $response->json();
        $reply = $data['choices'][0]['message']['content'] ?? $data['message']['content'] ?? null;

        if (is_array($reply)) {
            $reply = $this->flattenMultimodal($reply, null);
        }
        if ($reply === null) {
            ChatLog::create(['user_id' => auth()->id(), 'model' => $model, 'status' => 'error']);

            return response()->json([
                'error' => 'The AI server response did not match the expected format.',
                'detail' => $data,
            ], 502);
        }

        ChatLog::create([
            'user_id' => auth()->id(),
            'model' => $model,
            'status' => 'ok',
            'prompt_tokens' => $data['usage']['prompt_tokens'] ?? null,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
            'total_tokens' => $data['usage']['total_tokens'] ?? null,
        ]);

        return response()->json(['reply' => $this->finalizeReply((string) $reply, null)]);
    }

    private function flattenMultimodal(array $reply, ?int $sessionId): ?string
    {
        $text = '';
        $images = [];
        foreach ($reply as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            $url = $part['image_url']['url'] ?? null;
            if ($url) {
                $images[] = (string) $url;
            }
        }
        foreach ($images as $url) {
            if (str_starts_with(strtolower($url), 'data:image/')) {
                $saved = $this->materializeDataUrl($url, $sessionId);
                if ($saved) {
                    $text .= "\n\n![alt](".$saved.')';
                }
            } elseif (str_starts_with(strtolower($url), 'http')) {
                $text .= "\n\n![alt](".$url.')';
            }
        }

        return $text !== '' ? $text : null;
    }

    private function finalizeReply(string $text, ?int $sessionId): string
    {
        $text = $this->saveReplyImages($text, $sessionId);
        $text = $this->importFileRefs($text, $sessionId);

        [$clean, $results] = ActionRunner::run($text, auth()->id());
        if ($results !== []) {
            $clean .= "\n\n".implode("\n", $results);
        }

        return $clean;
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'history' => 'sometimes|array',
            'image_ids' => 'sometimes|array',
            'image_ids.*' => 'integer',
            'session_id' => 'sometimes|integer',
        ]);

        $url = rtrim((string) env('AI_API_URL', 'http://host.docker.internal:1234/v1/chat/completions'), '/');
        $model = Setting::get('ai_model') ?: (string) env('AI_MODEL', 'hermes-agent');

        $messages = [
            [
                'role' => 'system',
                'content' => (string) env('AI_SYSTEM_PROMPT', 'You are Hermes, a helpful and concise AI assistant. Whenever a request involves producing or processing a file (document, spreadsheet, presentation, PDF, image, code, report), you MUST send that file to the user by saving it and reporting it. Save it into the folder /home/ROG/MyAssistant/backend/storage/app/private/hermes-output, then include in your reply exactly one line per file: MEDIA: /home/ROG/MyAssistant/backend/storage/app/private/hermes-output/<filename>. Never claim you cannot send files — saving plus the MEDIA: line is how you send them. If a file is already saved elsewhere, just give its full absolute path in the reply.').ActionRunner::toolsInstruction(),
            ],
        ];

        $snapshot = ActionRunner::stateSnapshot(auth()->id());
        if ($snapshot !== '') {
            $messages[] = ['role' => 'system', 'content' => $snapshot];
        }

        foreach (($validated['history'] ?? []) as $turn) {
            if (! isset($turn['role'], $turn['content'])) {
                continue;
            }
            $content = $this->contentFor($turn);
            // API menolak turn ber-content kosong (400 INVALID_REQUEST_BODY).
            if (is_string($content) && trim($content) === '') {
                continue;
            }
            $messages[] = [
                'role' => (string) $turn['role'],
                'content' => $content,
            ];
        }

        $userContent = $this->contentFor([
            'content' => $validated['message'],
            'image_ids' => $validated['image_ids'] ?? [],
        ]);
        if (is_string($userContent) && trim($userContent) === '') {
            $userContent = '[...]';
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $this->linkMedia($validated['image_ids'] ?? [], $validated['session_id'] ?? null);

        try {
            $http = Http::timeout((int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)))
                ->acceptJson()
                ->asJson()
                ->withOptions(['stream' => true]);

            if ($apiKey = (string) env('AI_API_KEY')) {
                $http = $http->withToken($apiKey);
            }

            $response = $http->post($url, [
                'model' => $model,
                'messages' => $messages,
                'temperature' => (float) env('AI_TEMPERATURE', 0.7),
                'stream' => true,
            ]);
        } catch (\Throwable $e) {
            ChatLog::create(['user_id' => auth()->id(), 'model' => $model, 'status' => 'error']);
            Log::error('AI request failed', ['url' => $url, 'error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Unable to reach the local Hermes AI. Make sure your AI server (LM Studio/Ollama) is running.',
                'detail' => $e->getMessage(),
            ], 503);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $isStream = $response->successful() && str_contains($contentType, 'text/event-stream');

        if (! $isStream) {
            return $this->respondBuffered($response, $model);
        }

        $body = $response->toPsrResponse()->getBody();
        $sessionId = $validated['session_id'] ?? null;

        return response()->stream(function () use ($body, $model, $sessionId) {
            $accum = '';
            $fullText = '';

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
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            if (preg_match_all('/^data:\s*(\{.*\})\s*$/m', $accum, $matches)) {
                foreach ($matches[1] as $line) {
                    $decoded = json_decode($line, true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $delta = $decoded['choices'][0]['delta']['content'] ?? null;
                    if (is_string($delta)) {
                        $fullText .= $delta;
                    }
                }
            }

            $final = $this->finalizeReply($fullText, $sessionId);

            ChatLog::create([
                'user_id' => auth()->id(),
                'model' => $model,
                'status' => $fullText === '' ? 'error' : 'ok',
            ]);

            echo 'data: '.json_encode(['type' => 'final', 'text' => (string) $final])."\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}