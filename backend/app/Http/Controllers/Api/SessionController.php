<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    private function serialize(ChatSession $session): array
    {
        return [
            'id' => (string) $session->id,
            'title' => $session->title ?? 'New chat',
            'messages' => $session->messages ?? [],
            'created_at' => $session->created_at ? $session->created_at->toIso8601String() : null,
        ];
    }

    public function index()
    {
        $sessions = ChatSession::where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($s) => $this->serialize($s));

        return response()->json(['sessions' => $sessions]);
    }

    public function store()
    {
        $session = ChatSession::create(['user_id' => auth()->id(), 'title' => 'New chat']);

        return response()->json($this->serialize($session), 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:60',
            'messages' => 'sometimes|array',
        ]);

        $session = ChatSession::where('user_id', auth()->id())->findOrFail($id);

        if (array_key_exists('title', $data)) {
            $session->title = $data['title'];
        }
        if (array_key_exists('messages', $data)) {
            $session->messages = $data['messages'];
        }
        $session->save();

        return response()->json($this->serialize($session));
    }

    public function destroy(string $id)
    {
        $session = ChatSession::where('user_id', auth()->id())->findOrFail($id);
        $session->delete();

        return response()->json(['ok' => true]);
    }
}