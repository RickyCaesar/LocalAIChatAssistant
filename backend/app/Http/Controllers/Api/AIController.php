<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    public function models()
    {
        $raw = null;
        $error = null;

        try {
            $res = static::http()->get(static::baseUrl().'/models');
            $raw = $res->successful() ? $res->json('data') : null;
            $error = $res->successful() ? null : ($res->json('error') ?? $res->body());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($raw === null) {
            return response()->json([
                'error' => $error ?? 'Unable to fetch models from the local AI.',
                'models' => [],
            ], 502);
        }

        $models = collect($raw)
            ->map(fn ($m) => [
                'id' => (string) ($m['id'] ?? $m['name'] ?? ''),
                'owned_by' => (string) ($m['owned_by'] ?? ''),
                'free' => (bool) preg_match('/free/i', ($m['id'] ?? '').' '.($m['owned_by'] ?? '')),
            ])
            ->filter(fn ($m) => $m['id'] !== '')
            ->values();

        $free = $models->filter(fn ($m) => $m['free'])->values();

        return response()->json([
            'models' => $free->count() > 0 ? $free : $models,
            'current' => Setting::get('ai_model', (string) env('AI_MODEL', 'hermes-agent')),
            'free_only' => $free->count() > 0,
            'total_available' => $models->count(),
        ]);
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
        ]);
    }

    private function queueStats(): array
    {
        $done = \App\Models\Task::where('status', 'done');
        $avg = (int) round((clone $done)->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0);

        return [
            'total' => \App\Models\Task::count(),
            'done' => (clone $done)->count(),
            'error' => \App\Models\Task::where('status', 'error')->count(),
            'active' => \App\Models\Task::whereIn('status', ['queued', 'processing'])->count(),
            'avg_duration' => $avg,
            'by_type' => \App\Models\Task::selectRaw('type, COUNT(*) as total, AVG(duration_seconds) as avg_duration')
                ->groupBy('type')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($t) => [
                    'type' => (string) $t->type,
                    'count' => (int) $t->total,
                    'avg_duration' => (int) round((float) $t->avg_duration),
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
            'model' => Setting::get('ai_model', (string) env('AI_MODEL', 'hermes-agent')),
            'ai_timeout' => (int) Setting::get('ai_timeout', env('AI_TIMEOUT', 600)),
            'max_upload_mb' => (int) Setting::get('max_upload_mb', 20),
            'stt_model' => (string) Setting::get('stt_model', 'small'),
            'stt_language' => (string) Setting::get('stt_language', 'id'),
            'hermes_status' => $this->hermesStatus(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'ai_timeout' => 'sometimes|integer|min:5|max:3600',
            'max_upload_mb' => 'sometimes|integer|min:1',
            'stt_model' => 'sometimes|string|in:tiny,base,small,medium,large-v3',
            'stt_language' => 'sometimes|string|max:10',
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json(['saved' => true] + $data);
    }

    public function updateModel(Request $request)
    {
        $data = $request->validate(['model' => 'required|string|max:190']);
        Setting::set('ai_model', $data['model']);

        return response()->json(['model' => $data['model'], 'saved' => true]);
    }
}