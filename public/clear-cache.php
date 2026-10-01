<?php

/*
 | Чистење на кешот на серверот (компилирани прегледи, конфиг, рути).
 | Потребно на Plesk бидејќи `git pull` не го освежува компилираниот Blade-кеш,
 | па новите промени во прегледите понекогаш не се појавуваат.
 |
 | Клучот се чита од .env (CLEAR_CACHE_KEY) — без поставен клуч скриптата одбива.
 | Употреба:  https://smetkovodstvo.krste.mk/clear-cache.php?key=<CLEAR_CACHE_KEY>
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

header('Content-Type: text/plain; charset=utf-8');

$key = (string) config('app.clear_cache_key');
if ($key === '') {
    http_response_code(403);
    exit("Не е поставен CLEAR_CACHE_KEY во .env — скриптата е заклучена.\n");
}
if (! hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

/*
 | `package:discover` е ПРВ и е овде од горчливо искуство.
 |
 | Списокот со откриени пакети (bootstrap/cache/packages.php) го гради Composer,
 | а на Plesk Composer не се вика никогаш — деплојот е `git pull` врз качен
 | vendor/. Значи нов пакет пристигнува во vendor/, но серверот не знае за
 | неговиот service provider: кодот е таму и молчи.
 |
 | Така во ЕРП-от Sanctum влезе „успешно" (2.72.0) и падна: најавата работеше (не бара
 | чувар), а `me` и `logout` враќаа 500 „Auth guard [sanctum] is not defined."
 |
 | Оттогаш packages.php е ВО git (види bootstrap/cache/.gitignore), па `git pull`
 | го носи готов. Ова тука останува како полуга ако сепак се разиде — гради го
 | од vendor/composer/installed.json, без Composer.
 */
foreach (['package:discover', 'view:clear', 'route:clear', 'config:clear', 'cache:clear'] as $cmd) {
    try {
        Illuminate\Support\Facades\Artisan::call($cmd);
        echo $cmd . ': ' . trim(Illuminate\Support\Facades\Artisan::output()) . "\n";
    } catch (\Throwable $e) {
        echo $cmd . ': ' . $e->getMessage() . "\n";
    }
}

/*
 | Досега скриптата САМО чистеше. Градењето е поентата: без кеширана
 | конфигурација секое барање ги чита сите датотеки од config/ и го парсира
 | .env — мерено локално, подигањето е 129.5 ms наместо 93.7 ms.
 |
 | `route:cache` НАМЕРНО го нема, од две причини. Прво, рутите носат затворачи,
 | кои не може да се серијализираат. Второ, и поважно: кеширани рути што
 | заостанале зад кодот значат 404 за нова страница — брзо и погрешно е полошо
 | од бавно и точно.
 |
 | Заостанат кеш на конфигурација не може да се провлече: bootstrap/app.php го
 | фрла сам штом .env или нешто во config/ е поново од него.
 */
@set_time_limit(300);

echo "\n";

foreach (['config:cache', 'event:cache', 'view:cache'] as $cmd) {
    try {
        Illuminate\Support\Facades\Artisan::call($cmd);
        echo $cmd.': '.trim(Illuminate\Support\Facades\Artisan::output())."\n";
    } catch (\Throwable $e) {
        echo $cmd.': '.$e->getMessage()."\n";
    }
}

echo "\nГотово. Кешот е исчистен и изграден одново — освежи ја апликацијата (Ctrl+Shift+R).\n";
