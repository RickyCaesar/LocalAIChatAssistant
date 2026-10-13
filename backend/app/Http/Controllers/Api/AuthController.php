<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => Str::random(64),
        ]);

        return response()->json([
            'token' => $user->api_token,
            'user' => ['name' => $user->name, 'email' => $user->email, 'is_admin' => $user->is_admin],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => 'Email atau password salah.'], 401);
        }

        $user->api_token = Str::random(64);
        $user->save();

        return response()->json([
            'token' => $user->api_token,
            'user' => ['name' => $user->name, 'email' => $user->email, 'is_admin' => $user->is_admin],
        ]);
    }

    public function me()
    {
        return response()->json([
            'user' => [
                'id' => auth()->id(),
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
                'is_admin' => (bool) auth()->user()->is_admin,
            ],
        ]);
    }

    public function logout()
    {
        $user = auth()->user();
        $user->api_token = null;
        $user->save();

        return response()->json(['ok' => true]);
    }
}