<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function serialize(Notification $n): array
    {
        return [
            'id' => (int) $n->id,
            'title' => (string) $n->title,
            'message' => (string) $n->message,
            'type' => (string) $n->type,
            'link' => $n->link,
            'is_read' => (bool) $n->is_read,
            'created_at' => $n->created_at ? $n->created_at->toIso8601String() : null,
        ];
    }

    // Tanpa `after` = 30 terbaru (isi dropdown lonceng); `?after=<id>` = hanya
    // yang lebih baru (toast/push saat polling). `unread` & `latest` sekalian
    // agar frontend cukup satu request per poll.
    public function index(Request $request)
    {
        $base = Notification::where('user_id', auth()->id());
        $after = (int) $request->query('after', 0);

        $items = (clone $base)
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($n) => $this->serialize($n));

        return response()->json([
            'items' => $items,
            'unread' => (clone $base)->where('is_read', false)->count(),
            'latest' => (int) ((clone $base)->max('id') ?? 0),
        ]);
    }

    public function read(string $id)
    {
        Notification::where('user_id', auth()->id())->findOrFail($id)->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }

    public function readAll()
    {
        Notification::where('user_id', auth()->id())->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }
}
