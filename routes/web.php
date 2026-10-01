<?php

use Illuminate\Support\Facades\Route;

/*
 | Веб-екрани за работа НЕМА — се работи од локалната апликација преку
 | /api/v1. Прво пуштање и миграции: public/sistem.php. Кеш: public/clear-cache.php.
 | Приказите за печат (PDF) ќе дојдат тука во фаза 2.
 */
Route::get('/', fn () => response(config('app.name').' '.config('version.number')."\n", 200)
    ->header('Content-Type', 'text/plain; charset=utf-8'));
