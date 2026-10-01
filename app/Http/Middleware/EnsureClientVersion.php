<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Престара локална апликација добива 426 наместо погрешни податоци.
 *
 * Серверот и апликацијата се ажурираат ОДДЕЛНО. По изменет договор стара
 * инсталација не паѓа — тивко чита празно поле и покажува погрешен број, а
 * во книгите тоа е полошо од запрена програма. Праг: `version.local_app_min`.
 *
 * Без заглавие `X-App-Version` поминува: тоа е рачна проба (curl), не апликацијата.
 */
class EnsureClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $sent = trim((string) $request->header('X-App-Version'));
        if ($sent === '') {
            return $next($request);
        }

        $min = (string) config('version.local_app_min', '0.0.0');

        if (version_compare($sent, $min, '>=')) {
            return $next($request);
        }

        return response()->json([
            'error'   => 'client_outdated',
            'message' => 'Апликацијата е престара за оваа верзија на серверот. Потребна е верзија '.$min.' или понова.',
            'need'    => $min,
            'have'    => $sent,
            'latest'  => (string) config('version.local_app'),
        ], 426);
    }
}
