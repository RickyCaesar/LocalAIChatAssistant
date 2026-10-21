<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AIController extends Controller
{
    private static function baseUrl(): string
    {
        return rtrim((string) preg_replace('#/chat/completions$#', '', (string) env('AI_API_URL', 'http://host.docker.internal:8642/v1/chat/completions')), '/');
    }

    private static function http(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout(15)->acceptJson();

        if ($key = (string) env('AI_API_KEY')) {
            $client = $client->withToken($key);
        }

        return $client;
    }

    private function hermesStatus(): string
    {
        try {
            return static::http()->get(static::baseUrl().'/health')->successful() ? 'online' : 'offline';
        } catch (\Throwable) {
            return 'offline';
        }
    }

    // Kuota/limit akun (Nous) di balik Hermes. Gateway tak punya endpoint kuota,
    // jadi kita pakai token yang sudah ditulis Hermes di auth.json untuk satu GET
    // ke portal. ponytail: pisah ke bridge/host helper kalau auth.json dipindah/di-rename.
    private function hermesUsage(): array
    {
        $model = (string) env('AI_MODEL', 'hermes-agent');

        return Cache::remember('hermes.usage', 60, function () use ($model) {
            $file = (string) env('HERMES_AUTH_FILE', '/home/ROG/.hermes/auth.json');
            $auth = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            $entry = $auth['credential_pool']['nous'][0] ?? [];
            $token = (string) ($entry['access_token'] ?? '');

            if ($token === '') {
                return ['available' => false, 'model' => $model, 'reason' => 'Token akun tidak ditemukan di auth.json.'];
            }

            $base = rtrim((string) ($entry['portal_base_url'] ?? 'https://portal.nousresearch.com'), '/');

            try {
                $res = Http::timeout(8)->acceptJson()->withToken($token)->get($base.'/api/oauth/account');
            } catch (\Throwable $e) {
                return ['available' => false, 'model' => $model, 'reason' => 'Portal tidak terjangkau.'];
            }

            if (! $res->successful()) {
                return ['available' => false, 'model' => $model, 'reason' => 'Portal menolak (HTTP '.$res->status().').'];
            }

            $j = $res->json() ?: [];
            $sub = $j['subscription'] ?? [];
            $paid = $j['paid_service_access'] ?? [];

            return [
                'available' => true,
                'model' => $model,
                'plan' => (string) ($sub['plan'] ?? 'Free'),
                'tier' => $sub['tier'] ?? null,
                'credits_remaining' => (float) ($sub['credits_remaining'] ?? 0),
                'monthly_credits' => (float) ($sub['monthly_credits'] ?? 0),
                'purchased_credits_remaining' => (float) ($j['purchased_credits_remaining'] ?? 0),
                'total_usable_credits' => (float) ($paid['total_usable_credits'] ?? 0),
                'paid_access' => (bool) ($paid['paid_access'] ?? false),
                'period_end' => $sub['current_period_end'] ?? null,
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }

    // Draft Marp Markdown dari deskripsi user lewat Hermes; hasilnya dikembalikan
    // agar user bisa mengedit dulu sebelum dibuat PPTX. ponytail: panggilan
    // non-stream, tekanan presentasi biasanya kecil.
    public function presentationDraft(Request $request)
    {
        $data = $request->validate([
            'prompt' => 'required|string|max:4000',
            'material' => 'nullable|string|max:60000',
        ]);

        $model = (string) env('AI_MODEL', 'hermes-agent');
        $system = 'You design modern, visually rich slide decks as Marp Markdown. '
            .'Do NOT use tools and do NOT save files — reply with the Markdown text as your entire message, no explanations, no code fences. '
            ."Begin with YAML frontmatter:\n---\nmarp: true\ntheme: notebook\npaginate: true\n---\n"
            .'Separate every slide from the next with a line containing exactly three dashes (`---`). '
            .'Formatting: first slide gets a `<!-- _class: title -->` line then a `# Title` and one subtitle line; use '
            .'`<!-- _class: section -->` with a `## Heading` for part dividers in longer decks; every other slide has a `## heading` '
            .'and 3-6 short bullets (max ~8 words each, no walls of text). Use tables, `**bold**`, and one `<blockquote>` only when they '
            .'add clarity. Write in the same language as the user; use a few relevant emojis. For a distinctive look you MAY start the '
            .'deck with `<style>:root{--accent:#4f46e5;--accent2:#06b6d4}</style>` (tasteful hex pair). '
            .'If reference material is provided, base the content strictly on it and do not invent facts.';

        $userContent = $data['prompt'];
        if (! empty($data['material'])) {
            $userContent .= "\n\n--- REFERENCE MATERIAL ---\n".Str::limit($data['material'], 20000, "\n…");
        }

        try {
            $http = Http::timeout((int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)))->acceptJson()->asJson();
            if ($key = (string) env('AI_API_KEY')) {
                $http = $http->withToken($key);
            }
            $res = $http->post(static::baseUrl().'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'temperature' => 0.6,
                'stream' => false,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Tidak bisa menghubungi Hermes.', 'detail' => $e->getMessage()], 503);
        }

        if ($res->failed()) {
            return response()->json([
                'error' => $res->status() === 429
                    ? 'Kuota model gratis sedang jenuh (rate limit). Hermes sudah mencoba model lain — coba lagi beberapa saat.'
                    : 'Hermes mengembalikan error.',
                'detail' => $res->json('error.message') ?? $res->body(),
            ], 502);
        }

        $md = self::markdownFromReply((string) ($res->json('choices.0.message.content') ?? ''));
        if ($md === null || trim($md) === '') {
            return response()->json(['error' => 'Hermes tidak mengembalikan Markdown. Coba lagi.'], 502);
        }

        return response()->json(['markdown' => $md]);
    }

    // Hermes (agent mode) kadang menulis .md ke /home/ROG lalu membalas narasi.
    // Utamakan isi filenya, lalu blok kode, lalu teks mentah kalau memang Markdown.
    private static function markdownFromReply(string $text): ?string
    {
        if (preg_match_all('#/home/ROG/[^\s`"\'),;]+\.md#i', $text, $m)) {
            foreach (array_reverse($m[0]) as $path) {
                if (is_file($path)) {
                    $body = trim((string) file_get_contents($path));
                    if ($body !== '') {
                        return self::stripFences($body);
                    }
                }
            }
        }

        if (preg_match('/```[a-z]*\s*\n(.*?)\n```/is', $text, $m) && str_contains($m[1], 'marp:')) {
            return trim($m[1]);
        }

        $clean = self::stripFences($text);
        $head = ltrim($clean);
        if (str_contains($clean, 'marp:') || str_starts_with($head, '#') || str_starts_with($head, '---')) {
            return $clean;
        }

        return null;
    }

    private static function stripFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```[a-z]*\s*\n(.*?)\n```\s*$/is', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    public function analytics()
    {
        $totalRequests = ChatLog::count();
        $okRequests = ChatLog::where('status', 'ok')->count();

        $start = now()->subDays(6)->startOfDay();
        $rows = ChatLog::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as requests, SUM(total_tokens) as tokens')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $byDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $r = $rows->get($d);
            $byDay[] = [
                'date' => $d,
                'requests' => $r ? (int) $r->requests : 0,
                'tokens' => $r ? (int) $r->tokens : 0,
            ];
        }

        $byModel = ChatLog::selectRaw('model, COUNT(*) as requests, SUM(total_tokens) as tokens')
            ->groupBy('model')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($m) => [
                'model' => (string) $m->model,
                'requests' => (int) $m->requests,
                'tokens' => (int) $m->tokens,
            ]);

        $byUser = \App\Models\User::leftJoin('chat_logs', 'chat_logs.user_id', '=', 'users.id')
            ->selectRaw('users.id, users.name, users.email, users.is_admin, COUNT(chat_logs.id) as requests, COALESCE(SUM(chat_logs.total_tokens), 0) as tokens')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.is_admin')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'is_admin' => (bool) $u->is_admin,
                'requests' => (int) $u->requests,
                'tokens' => (int) $u->tokens,
            ]);

        return response()->json([
            'stats' => [
                'total_requests' => $totalRequests,
                'success_requests' => $okRequests,
                'failed_requests' => $totalRequests - $okRequests,
                'success_rate' => $totalRequests > 0 ? round($okRequests / $totalRequests, 4) : null,
                'total_tokens' => (int) ChatLog::where('status', 'ok')->sum('total_tokens'),
                'prompt_tokens' => (int) ChatLog::where('status', 'ok')->sum('prompt_tokens'),
                'completion_tokens' => (int) ChatLog::where('status', 'ok')->sum('completion_tokens'),
                'today_requests' => ChatLog::whereDate('created_at', now()->toDateString())->count(),
                'total_users' => \App\Models\User::count(),
            ],
            'by_day' => $byDay,
            'by_model' => $byModel,
            'by_user' => $byUser,
            'queue' => $this->queueStats(),
            'content' => $this->contentStats(),
            'hermes_status' => $this->hermesStatus(),
            'hermes_usage' => $this->hermesUsage(),
        ]);
    }

    private function queueStats(): array
    {
        // Durasi selalu dihitung dari started_at -> finished_at (kolom
        // duration_seconds tidak pernah diisi; lihat Task::getDurationAttribute).
        $done = \App\Models\Task::where('status', 'done')->get(['type', 'started_at', 'finished_at']);

        return [
            'total' => \App\Models\Task::count(),
            'done' => $done->count(),
            'error' => \App\Models\Task::where('status', 'error')->count(),
            'active' => \App\Models\Task::whereIn('status', ['queued', 'processing'])->count(),
            'avg_duration' => (int) round($done->avg('duration') ?? 0),
            'by_type' => \App\Models\Task::selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($t) => [
                    'type' => (string) $t->type,
                    'count' => (int) $t->total,
                    'avg_duration' => (int) round($done->where('type', $t->type)->avg('duration') ?? 0),
                ]),
        ];
    }

    private function contentStats(): array
    {
        return [
            'sessions' => \App\Models\ChatSession::count(),
            'media' => \App\Models\Media::count(),
            'todos' => \App\Models\Todo::count(),
            'events' => \App\Models\Event::count(),
        ];
    }

    public function settings()
    {
        return response()->json([
            'model' => (string) env('AI_MODEL', 'hermes-agent'),
            'ai_timeout' => (int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)),
            'max_upload_mb' => (int) Setting::get('max_upload_mb', 20),
            'stt_model' => (string) Setting::get('stt_model', 'small'),
            'stt_language' => (string) Setting::get('stt_language', 'id'),
            'tts_enabled' => (int) Setting::get('tts_enabled', 1),
            'tts_voice' => (string) Setting::get('tts_voice', \App\Support\TTSService::defaultVoice()),
            'tts_speed' => (float) Setting::get('tts_speed', 1),
            'hermes_status' => $this->hermesStatus(),
            // Tema global (diatur admin) — semua user mengikutinya.
            'theme_palette' => (string) Setting::get('theme_palette', 'klasik'),
            'theme_dark' => (string) Setting::get('theme_dark', 'nila'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'ai_timeout' => 'sometimes|integer|min:5|max:3600',
            'max_upload_mb' => 'sometimes|integer|min:1',
            'stt_model' => 'sometimes|string|in:tiny,base,small,medium,large-v3',
            'stt_language' => 'sometimes|string|max:10',
            'tts_enabled' => 'sometimes|boolean',
            'tts_voice' => 'sometimes|string|max:100',
            'tts_speed' => 'sometimes|numeric|min:0.5|max:2',
            'theme_palette' => 'sometimes|string|in:klasik,senja,mint',
            'theme_dark' => 'sometimes|string|in:nila,ungu,hijau',
        ]);

        if (isset($data['tts_voice']) && \App\Support\TTSService::modelPath($data['tts_voice']) === null) {
            return response()->json(['error' => 'Model suara TTS tidak ditemukan.'], 422);
        }

        foreach ($data as $key => $value) {
            Setting::set($key, is_bool($value) ? (int) $value : $value);
        }

        return response()->json(['saved' => true] + $data);
    }
}