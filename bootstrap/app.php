<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 | Кеширана конфигурација што ЗАОСТАНУВА зад кодот се фрла тука, пред да
 | биде прочитана (истото како кај ЕРП-от).
 |
 | Без ова измена во .env на серверот или нова верзија со `git pull` тивко не
 | би важела додека некој не се сети да го исчисти кешот. Се брише САМО
 | кешот: незграден кеш значи побавно и точно, заостанат — брзо и погрешно.
 */
$kesiranaKonfiguracija = __DIR__.'/cache/config.php';

if (is_file($kesiranaKonfiguracija)) {
    $koren = dirname(__DIR__);
    $najnovoVoIzvorot = (int) @filemtime($koren.'/.env');

    foreach (glob($koren.'/config/*.php') ?: [] as $datoteka) {
        $najnovoVoIzvorot = max($najnovoVoIzvorot, (int) @filemtime($datoteka));
    }

    if ($najnovoVoIzvorot > (int) filemtime($kesiranaKonfiguracija)) {
        @unlink($kesiranaKonfiguracija);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Редоследот е важен: стара апликација се запира пред сè друго; стражарот
        // на базата важи за секое запишување; уредот се бележи последен.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureClientVersion::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureSchemaCurrent::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\TouchApiToken::class);

        $middleware->alias([
            'active'     => \App\Http\Middleware\EnsureActiveUser::class,
            'firm'       => \App\Http\Middleware\ResolveFirm::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'super'      => \App\Http\Middleware\EnsureSuper::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Апликацијата ја покажува пораката како што е — значи мора да е на
        // македонски и секогаш во ист облик { error, message }.
        $api = fn (Request $r) => $r->is('api/*');

        $exceptions->render(function (AuthenticationException $e, Request $r) use ($api) {
            return $api($r) ? response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Не сте најавени или пристапот е одземен. Најавете се повторно.',
            ], 401) : null;
        });

        $exceptions->render(function (ValidationException $e, Request $r) use ($api) {
            return $api($r) ? response()->json([
                'error'   => 'validation',
                'message' => $e->validator->errors()->first(),
                'errors'  => $e->errors(),
            ], 422) : null;
        });

        $exceptions->render(function (\App\Exceptions\DocumentLocked $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['error' => 'locked', 'message' => $e->getMessage()], 409) : null;
        });

        // Тригер во базата одбил промена на прокнижен налог (`LedgerGuard`).
        // Ако стигне дотука, кодот промашил некаде — но човекот добива точна
        // порака и 409, не „Server Error“.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $r) use ($api) {
            if (! $api($r) || ! str_contains($e->getMessage(), \App\Support\LedgerGuard::TAG)) {
                return null;
            }

            return response()->json(['error' => 'locked', 'message' => 'Прокнижен налог не смее да се менува ниту брише.'], 409);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($api) {
            if (! $api($r)) {
                return null;
            }

            return response()->json([
                'error'   => 'not_found',
                'message' => $e->getPrevious() instanceof ModelNotFoundException
                    ? 'Записот не постои.'
                    : 'Непозната адреса на серверот.',
            ], 404);
        });
    })->create();
