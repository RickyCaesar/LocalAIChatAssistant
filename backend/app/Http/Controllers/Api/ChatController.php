<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\ChatSession;
use App\Models\Media;
use App\Models\Setting;
use App\Support\ActionRunner;
use App\Support\FilePreview;
use App\Support\NotulenBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    // Ditempel di pesan user terakhir agar model tidak mengikuti jawaban lama
    // yang mengaku tidak punya akses file atau menyuruh buka folder.
    private function runtimeBriefing(): string
    {
        $out = rtrim((string) env('HERMES_OUTPUT_HOST', '/home/ROG/MyAssistant/backend/storage/app/private/hermes-output'), '/');

        return "\n\n[ATURAN APLIKASI MYASSISTANT — WAJIB DIIKUTI] Kamu berjalan di MyAssistant dan punya tool terminal, file, serta execute_code. Abaikan jawaban lama yang mengatakan kamu tidak punya akses. Jangan menulis kode untuk dijalankan user, jangan menyuruh user membuka folder/File Explorer, dan jangan mengaku tidak bisa menyimpan file. Kerjakan sendiri sekarang. Simpan setiap berkas hasil ke {$out}/ lalu balas dengan tepat satu baris MEDIA: <path> per berkas. Aplikasi hanya mengimpor berkas dari folder hermes-output itu; jangan mengandalkan path lain. Untuk notulen rapat: jangan menulis skrip sendiri, susun isinya lalu emit ACTION: NOTULEN_BUILD <JSON> — aplikasinya yang menyusun DOCX (PDF bisa dibuat dari berkas itu di Gallery). Untuk notulen, jangan emit MEDIA karena aplikasi mengirim DOCX dari ACTION itu.";
    }

    // Kutipan balasan hanya untuk model; dipotong 600 karakter agar ringan.
    private function replyQuote(?array $replyContext): string
    {
        $content = trim((string) ($replyContext['content'] ?? ''));
        if ($content === '') {
            return '';
        }

        return '[Membalas pesan '.(($replyContext['role'] ?? '') === 'user' ? 'saya' : 'Hermes')
            .': "'.mb_substr($content, 0, 600).'"]'."\n\n";
    }

    private function isNotulenRequest(string $message, array $history = []): bool
    {
        if (preg_match('/\b(notulen|notulensi|transkrip)/i', $message) !== 1) {
            return false;
        }
        if (mb_strlen($message) > 4000) {
            return true;
        }
        // Pertanyaan lanjutan tentang hasil yang sudah ada bukan permintaan susun baru.
        if ($history !== [] && preg_match('/\b(buat|buatkan|susun|bikin|generate|olah|proses)\b/i', $message) !== 1) {
            return false;
        }

        return true;
    }

    // Pertanyaan "mana DOCX-nya?" cukup dijawab tautan hasil terakhir, tanpa agent
    // yang bisa tersesat mencari file lama di folder output.
    private function lastMediaReply(string $message, array $history): ?string
    {
        if (mb_strlen($message) > 300
            || preg_match('/\b(mana|dimana|di mana)\b/i', $message) !== 1
            || preg_match('/\b(docx|pdf|file|berkas|dokumen|notulen|notulensi)/i', $message) !== 1) {
            return null;
        }

        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            if (preg_match('/\[([^\]]+)\]\(\/api\/media\/(\d+)[^)]*\)/', (string) ($turn['content'] ?? ''), $m)
                && Media::where('user_id', auth()->id())->whereKey((int) $m[2])->exists()) {
                return '✔ Berkas terakhir: ['.$m[1].'](/api/media/'.$m[2].')';
            }
        }

        return null;
    }

    private function contentFor(array $turn): array|string
    {
        $text = (string) ($turn['content'] ?? '');
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
                'model' => (string) env('AI_MODEL', 'hermes-agent'),
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
        $containerRoot = realpath(storage_path('app/private'));
        $hostRoot = rtrim((string) env('HERMES_OUTPUT_HOST', '/home/ROG/MyAssistant/backend/storage/app/private/hermes-output'), '/');
        if ($containerRoot === false) {
            return null;
        }
        $outputRoot = $containerRoot.DIRECTORY_SEPARATOR.'hermes-output';

        if (str_starts_with($path, $outputRoot.'/')) {
            $real = $path;
        } elseif ($hostRoot !== '' && str_starts_with($path, $hostRoot.'/')) {
            $real = $outputRoot.'/'.substr($path, strlen($hostRoot) + 1);
        } else {
            return null;
        }

        $real = realpath($real);
        if ($real === false || ! str_starts_with($real, $outputRoot.DIRECTORY_SEPARATOR) || ! is_file($real)) {
            return null;
        }
        if (filesize($real) > 25 * 1024 * 1024) {
            return null;
        }

        $bytes = file_get_contents($real);
        if ($bytes === false || $bytes === '') {
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
            'model' => (string) env('AI_MODEL', 'hermes-agent'),
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
                'error' => $response->status() === 429
                    ? 'Kuota model gratis sedang jenuh (rate limit). Hermes sudah mencoba model lain — coba lagi beberapa saat.'
                    : 'The local AI server returned an error.',
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

    private function finalizeReply(string $text, ?int $sessionId, bool $notulen = false): string
    {
        $text = $this->saveReplyImages($text, $sessionId);
        $text = $this->importFileRefs($text, $sessionId);

        [$clean, $results] = ActionRunner::run($text, auth()->id(), $sessionId);
        if ($results !== []) {
            $clean .= "\n\n".implode("\n", $results);
        } elseif ($notulen && ($doc = NotulenBuilder::decode($text)) !== null && isset($doc['judul'])) {
            // Model kadang hanya mengirim JSON tanpa baris ACTION; bangun tetap.
            try {
                $media = NotulenBuilder::save($doc, (int) auth()->id(), $sessionId);
                $clean = '✔ Notulen dibuat: ['.$media->filename.'](/api/media/'.$media->id.')';
            } catch (\Throwable $e) {
                $clean .= "\n\n✖ Gagal membuat notulen: ".$e->getMessage();
            }
        }

        return $clean;
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'reply_context' => 'sometimes|array',
            'history' => 'sometimes|array',
            'image_ids' => 'sometimes|array',
            'image_ids.*' => 'integer',
            'session_id' => 'sometimes|integer',
        ]);

        if ($lastMedia = $this->lastMediaReply((string) $validated['message'], $validated['history'] ?? [])) {
            return response()->json(['reply' => $lastMedia]);
        }

        $isNotulen = $this->isNotulenRequest((string) $validated['message'], $validated['history'] ?? []);
        // Notulen lewat proxy raw (tanpa inti Hermes) supaya transkrip panjang jadi
        // satu dokumen utuh dan model menyalin semua angka; chat biasa pakai gateway.
        $url = $isNotulen
            ? rtrim((string) env('AI_NOTULEN_URL', 'http://host.docker.internal:8645/v1/chat/completions'), '/')
            : rtrim((string) env('AI_API_URL', 'http://host.docker.internal:1234/v1/chat/completions'), '/');
        $model = (string) env('AI_MODEL', 'hermes-agent');

        // Notulen memakai prompt khusus saja; instruksi MEDIA/tool umum membuat model
        // menyisipkan artefak ke JSON panjang.
        $system = $isNotulen
            ? ActionRunner::notulenInstruction()
            : (string) env('AI_SYSTEM_PROMPT', 'You are Hermes, a helpful and concise AI assistant. Whenever a request involves producing or processing a file (document, spreadsheet, presentation, PDF, image, code, report), save it into /home/ROG/MyAssistant/backend/storage/app/private/hermes-output, then include in your reply exactly one line per file: MEDIA: /home/ROG/MyAssistant/backend/storage/app/private/hermes-output/<filename>. The app only imports files from that folder, so do not rely on other paths. To make a PDF, run /home/ROG/MyAssistant/tools/topdf.sh <file inside that folder> and send the printed PDF path. Never claim you cannot send files — saving plus the MEDIA: line is how you send them.').ActionRunner::toolsInstruction();

        $system .= "\n[req:".Str::uuid()."]";

        $messages = [
            [
                'role' => 'system',
                'content' => $system,
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
        // Kutipan balasan hanya ditambahkan ke isi yang dibaca model, bukan ke
        // pesan yang dipakai routing (pertanyaan pendek tetap dikenali).
        $quote = $this->replyQuote($validated['reply_context'] ?? null);
        if ($quote !== '') {
            if (is_array($userContent) && isset($userContent[0]['text'])) {
                $userContent[0]['text'] = $quote.(string) $userContent[0]['text'];
            } else {
                $userContent = $quote.(string) $userContent;
            }
        }
        $briefing = $isNotulen
            ? "\n\n[OUTPUT WAJIB] Transkrip lengkap ada di atas. Jawab hanya dengan satu baris ACTION: NOTULEN_BUILD <JSON satu baris>. Jangan memanggil tool, jangan menulis file, jangan membuat ringkasan, jangan memakai markdown."
            : $this->runtimeBriefing();
        if (is_array($userContent) && isset($userContent[0]['text'])) {
            $userContent[0]['text'] = (string) $userContent[0]['text'].$briefing;
        } else {
            $userContent = (string) $userContent.$briefing;
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $this->linkMedia($validated['image_ids'] ?? [], $validated['session_id'] ?? null);

        try {
            // Sesi unik per request: aplikasi mengirim riwayat sendiri, sedangkan sesi
            // Hermes yang dipakai ulang membuat request antre di belakang run yang macet.
            // Tanpa X-Hermes-Session-Id: gateway memakai riwayat yang dikirim aplikasi.
            // ID sesi diturunkan dari system prompt + pesan pertama; nonce di system
            // prompt menjaga tiap request tetap sesi baru tanpa membuang riwayat itu.
            $http = Http::timeout((int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)))
                ->acceptJson()
                ->asJson()
                // read_timeout membuat loop bangun tiap beberapa detik walau agent
                // sedang menjalankan tool tanpa output, supaya tombol Stop terdeteksi.
                // shortcut: Stop memakai putus koneksi + poll 2 dtk, bukan endpoint
                // cancel; upgrade ke /v1/runs/{id}/stop kalau gateway berubah.
                ->withOptions(['stream' => true, 'read_timeout' => 2]);

            if ($apiKey = (string) env('AI_API_KEY')) {
                $http = $http->withToken($apiKey);
            }
            if ($isNotulen) {
                // Proxy hanya menerima Host loopback; host.docker.internal ditolak.
                $port = parse_url($url, PHP_URL_PORT) ?: 80;
                $http = $http->withHeaders(['Host' => 'localhost:'.$port]);
            }

            $payload = [
                // Notulen memakai model 1M konteks yang tahan transkrip panjang;
                // model default tetap dipakai untuk chat biasa.
                'model' => $isNotulen ? (string) env('AI_NOTULEN_MODEL', 'meituan/longcat-2.5:free') : $model,
                'messages' => $messages,
                'temperature' => $isNotulen ? 0.0 : (float) env('AI_TEMPERATURE', 0.7),
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ];
            if ($isNotulen) {
                // Proxy raw: nonaktifkan reasoning lewat parameter OpenAI, bukan model_options.
                $payload['reasoning'] = ['enabled' => false];
                // shortcut: 16k token menutup 20-30 blok; naikkan bila model pangkas berhenti di tengah JSON
                $payload['max_tokens'] = (int) env('AI_NOTULEN_MAX_TOKENS', 16384);
            }

            $response = $http->post($url, $payload);
        } catch (\Throwable $e) {
            ChatLog::create(['user_id' => auth()->id(), 'model' => $model, 'status' => 'error']);
            Log::error('AI request failed', ['url' => $url, 'error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Unable to reach the local AI server. Make sure the Hermes gateway (chat) and the Hermes proxy (notulen) are running.',
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

        return response()->stream(function () use ($body, $model, $sessionId, $isNotulen) {
            $accum = '';
            $fullText = '';
            $usage = null;

            while (! $body->eof()) {
                try {
                    $chunk = $body->read(8192);
                } catch (\RuntimeException $e) {
                    // Timeout baca bukan error upstream: lanjut untuk cek interrupt.
                    if (! ($body->getMetadata('timed_out') ?? false)) {
                        throw $e;
                    }
                    $chunk = '';
                }
                if ($chunk === '' || $chunk === false) {
                    if ($body->eof()) {
                        break;
                    }
                    // Komentar SSE sekaligus ping: memaksa PHP menulis agar
                    // koneksi browser yang sudah ditutup (Stop) terdeteksi.
                    echo ": ping\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    if (connection_aborted()) {
                        // Tutup koneksi ke Hermes; gateway membatalkan agent yang
                        // sedang berjalan saat kliennya putus.
                        $body->close();

                        return;
                    }
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
                    if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                        $usage = $decoded['usage'];
                    }
                }
            }

            $final = $this->finalizeReply($fullText, $sessionId, $isNotulen);

            ChatLog::create([
                'user_id' => auth()->id(),
                'model' => $model,
                'status' => $fullText === '' ? 'error' : 'ok',
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
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