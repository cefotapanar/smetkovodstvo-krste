<?php

namespace App\Http\Middleware;

use App\Support\SchemaState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Не дозволува ЗАПИШУВАЊЕ додека базата заостанува зад кодот (копија од ЕРП-от,
 * само за API — веб-екрани тука нема).
 *
 * На Plesk прво стигнува кодот (`git pull`), па дури потоа некој ги пушта
 * миграциите. Во тој меѓупростор нов код гледа стара база; во книгите тоа не
 * смее да заврши со половина запишан налог. Читањето поминува.
 *
 * Стражарот НИКОГАШ не смее сам да биде причина за прекин: ако проверката
 * не успее, барањето поминува и грешката се пријавува.
 */
class EnsureSchemaCurrent
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * login/logout — инаку додека базата заостанува апликацијата не би можела
     * ниту да се најави за да ЧИТА, а администраторот да ја пушти миграцијата.
     */
    private const ALWAYS_ALLOW = ['api/v1/login', 'api/v1/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        // Стандардот е ВКЛУЧЕН и кога клучот го нема (стар кеширан конфиг):
        // „не знам“ не смее да значи „изгасни ја заштитата“.
        if (! config('schema.guard', true)
            || in_array($request->method(), self::READ_METHODS, true)
            || SchemaState::isPaused()
            // Миграцијата од апликацијата не смее да ја сопре стражарот што чека на неа.
            || $request->is(...self::ALWAYS_ALLOW)
            || $request->routeIs('api.v1.system.schema.*')) {
            return $next($request);
        }

        try {
            $pending = SchemaState::pending();
        } catch (\Throwable $e) {
            report($e);

            return $next($request);
        }

        if ($pending === []) {
            return $next($request);
        }

        return response()->json([
            'error'   => 'schema_outdated',
            'message' => 'Базата не е ажурирана на верзијата на серверот, па запишувањето е привремено сопрено. Прегледот работи нормално.',
            'pending' => count($pending),
        ], 503)->header('Retry-After', '60');
    }
}
