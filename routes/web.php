<?php

use App\Projekt\ProjektController;
use Illuminate\Support\Facades\Route;

/*
 | Веб-екрани за сметководствената работа НЕМА — таа е во Windows програмата
 | преку /api/v1. Прво пуштање и миграции: public/sistem.php.
 |
 | ПРИВРЕМЕНО: работната табла на проектот (план + прашања и одлуки) додека
 | трае изработката. Се брише во целост на крајот — види CLAUDE.md.
 */
Route::get('/login', [ProjektController::class, 'loginForm'])->name('login');
Route::post('/login', [ProjektController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [ProjektController::class, 'logout'])->name('logout');

Route::get('/projekt/izvoz', [ProjektController::class, 'export'])->middleware('throttle:30,1');

Route::middleware(['auth', 'active.web'])->group(function () {
    Route::get('/', [ProjektController::class, 'board'])->name('projekt');
    Route::post('/projekt/stavki', [ProjektController::class, 'store'])->name('projekt.store');
    Route::post('/projekt/stavki/{item}/komentar', [ProjektController::class, 'comment'])->name('projekt.comment');
    Route::post('/projekt/stavki/{item}/odluka', [ProjektController::class, 'decide'])->name('projekt.decide');
    Route::post('/projekt/stavki/{item}/otvori', [ProjektController::class, 'reopen'])->name('projekt.reopen');
    Route::post('/projekt/korisnici', [ProjektController::class, 'storeUser'])->name('projekt.users');
});
