<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // GET requests allow the token via ?auth= so plain <img> tags (which
        // cannot set an Authorization header) can load protected media.
        $token = $request->bearerToken()
            ?? ($request->isMethod('GET') ? (string) $request->query('auth') : null);

        if (! $token) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = User::where('api_token', $token)->first();

        if (! $user) {
            return response()->json(['error' => 'Invalid or expired token.'], 401);
        }

        auth()->setUser($user);

        return $next($request);
    }
}