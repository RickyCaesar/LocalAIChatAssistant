<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::selectRaw('users.id, users.name, users.email, users.is_admin, users.created_at, COUNT(chat_logs.id) as requests, COALESCE(SUM(chat_logs.total_tokens), 0) as tokens')
            ->leftJoin('chat_logs', 'chat_logs.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.is_admin', 'users.created_at')
            ->orderBy('users.id')
            ->get()
            ->map(fn ($u) => $this->payload($u));

        return response()->json(['users' => $users]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:6',
            'is_admin' => 'boolean',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => Str::random(64),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return response()->json(['user' => $this->payload($user)], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'is_admin' => 'boolean',
        ]);

        if ($request->has('name')) {
            $user->name = $data['name'];
        }
        if ($request->has('email')) {
            $user->email = $data['email'];
        }
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if ($request->has('is_admin')) {
            if ($user->id === auth()->id() && ! $request->boolean('is_admin')) {
                return response()->json(['error' => 'Tidak bisa mencabut status admin dari akun sendiri.'], 422);
            }
            $user->is_admin = $request->boolean('is_admin');
        }
        $user->save();

        return response()->json(['user' => $this->payload($user)]);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return response()->json(['error' => 'Tidak bisa menghapus akun sendiri.'], 422);
        }

        $mediaPaths = Media::where('user_id', $user->id)->pluck('path');
        $taskPaths = Task::where('user_id', $user->id)->get()
            ->map(fn (Task $task) => (string) ($task->payload['path'] ?? ''))
            ->filter()
            ->values();

        $user->delete();

        foreach ($mediaPaths as $path) {
            @unlink(storage_path('app/private/'.$path));
        }
        foreach ($taskPaths as $path) {
            @unlink(storage_path('app/'.$path));
        }

        return response()->json(['ok' => true]);
    }

    private function payload(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'email' => (string) $u->email,
            'is_admin' => (bool) $u->is_admin,
            'requests' => (int) ($u->requests ?? 0),
            'tokens' => (int) ($u->tokens ?? 0),
            'created_at' => $u->created_at ? $u->created_at->toIso8601String() : null,
        ];
    }
}