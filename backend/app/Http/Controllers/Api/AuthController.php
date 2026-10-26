<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
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

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|max:190|unique:users,email,'.auth()->id(),
            'current_password' => 'required_with:new_password|string',
            'new_password' => 'sometimes|string|min:6',
        ]);

        $user = auth()->user();

        if (isset($data['new_password']) && ! Hash::check((string) $data['current_password'], $user->password)) {
            return response()->json(['error' => 'Password saat ini salah.'], 422);
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }
        if (isset($data['new_password'])) {
            $user->password = Hash::make($data['new_password']);
        }
        $user->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
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