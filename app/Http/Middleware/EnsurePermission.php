<?php

namespace App\Http\Middleware;

use App\Models\Firm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:клуч` — преглед за GET, `клуч.write` за сè што запишува.
 *
 * Мора да стои ПО `firm`: правата се по фирма. Рута со `permission:` без
 * `firm` е програмерска грешка и паѓа гласно (500), наместо обичен корисник
 * тивко да нема права, а главниот администратор тивко да ги има сите.
 */
class EnsurePermission
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (Firm::current() === null) {
            throw new \LogicException("Рутата бара „permission:{$key}“ без „firm“ пред неа.");
        }

        $user = $request->user();

        if (! $user || ! $user->hasPermission($key)) {
            return response()->json([
                'error'   => 'forbidden',
                'message' => 'Немате пристап до овој дел.',
            ], 403);
        }

        if (in_array($request->method(), self::WRITE_METHODS, true) && ! $user->canWrite($key)) {
            return response()->json([
                'error'   => 'forbidden',
                'message' => 'Немате дозвола за измена во овој дел (само преглед).',
            ], 403);
        }

        return $next($request);
    }
}
