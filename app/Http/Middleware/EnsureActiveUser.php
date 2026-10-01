<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Деактивиран корисник не работи ни со стар токен.
 *
 * Деактивирањето ги брише и токените (`SaveUser`), но ова е втора брава:
 * токен создаден во истиот миг, или запис сменет рачно во базата, не смее да
 * остане отворена врата.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json([
                'error'   => 'inactive',
                'message' => 'Корисникот е деактивиран.',
            ], 403);
        }

        return $next($request);
    }
}
