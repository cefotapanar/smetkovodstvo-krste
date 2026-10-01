<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Систем (фирми, корисници, улоги, база) — само главен администратор. */
class EnsureSuper
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuper()) {
            return response()->json([
                'error'   => 'forbidden',
                'message' => 'Само главниот администратор има пристап до овој дел.',
            ], 403);
        }

        return $next($request);
    }
}
