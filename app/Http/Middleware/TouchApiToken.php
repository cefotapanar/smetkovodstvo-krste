<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP и верзија на апликацијата врз токенот — за „Пријавени уреди“.
 * Запишува САМО кога нешто се сменило: апликацијата праќа десетици повици
 * во минута, а `last_used_at` Sanctum веќе го обновува сам.
 */
class TouchApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof ApiToken) {
            $ip = (string) $request->ip();
            $version = trim((string) $request->header('X-App-Version')) ?: null;

            if ($token->last_ip !== $ip || $token->app_version !== $version) {
                // Тивко: дневникот на уредите не смее да собори вистински повик.
                rescue(fn () => $token->forceFill(['last_ip' => $ip, 'app_version' => $version])->save(), null, false);
            }
        }

        return $next($request);
    }
}
