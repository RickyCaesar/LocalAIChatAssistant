<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    private function serialize(Todo $todo): array
    {
        return [
            'id' => (int) $todo->id,
            'title' => (string) $todo->title,
            'done' => (bool) $todo->done,
            'due_at' => $todo->due_at ? $todo->due_at->toIso8601String() : null,
            'created_at' => $todo->created_at ? $todo->created_at->toIso8601String() : null,
        ];
    }

    public function index()
    {
        $todos = Todo::where('user_id', auth()->id())
            ->orderBy('done')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => $this->serialize($t));

        return response()->json(['todos' => $todos]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'due_at' => 'nullable|date',
        ]);

        $todo = Todo::create([
            'user_id' => auth()->id(),
            'title' => $data['title'],
            'due_at' => $data['due_at'] ?? null,
        ]);

        return response()->json(['todo' => $this->serialize($todo)], 201);
    }

    public function update(Request $request, string $id)
    {
        $todo = Todo::where('user_id', auth()->id())->findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'done' => 'sometimes|boolean',
            'due_at' => 'nullable|date',
        ]);

        if (array_key_exists('title', $data)) {
            $todo->title = $data['title'];
        }
        if (array_key_exists('done', $data)) {
            $todo->done = $data['done'];
        }
        if (array_key_exists('due_at', $data)) {
            $todo->due_at = $data['due_at'];
        }
        $todo->save();

        return response()->json(['todo' => $this->serialize($todo)]);
    }

    public function destroy(string $id)
    {
        Todo::where('user_id', auth()->id())->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}