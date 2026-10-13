<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->user()?->is_admin) {
            return response()->json(['error' => 'Akses khusus admin.'], 403);
        }

        return $next($request);
    }
}