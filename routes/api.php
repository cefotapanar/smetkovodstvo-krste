<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

/*
 | API за локалната апликација — договорот е во API.md.
 | Нова рута прво се запишува таму, па дури потоа тука.
 |
 | Слоеви на заштита, по ред:
 |   auth:sanctum → active → (firm → permission:клуч) или super
 | Рута во рамки на фирма БЕЗ `firm` не смее да постои — `permission` тоа го фаќа.
 */

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('ping', [AuthController::class, 'ping'])->name('ping');
    Route::post('login', [AuthController::class, 'login'])->name('login')->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // ── Во рамки на фирма (X-Firm) ──────────────────────────────────────
        Route::middleware('firm')->group(function () {
            Route::get('firm', [AuthController::class, 'firm'])->name('firm');
        });

        // ── Систем — само главен администратор ─────────────────────────────
        Route::middleware('super')->prefix('system')->name('system.')->group(function () {
            Route::get('schema', [SystemController::class, 'schema'])->name('schema.status');
            Route::post('schema/migrate', [SystemController::class, 'migrate'])->name('schema.migrate');
            Route::post('schema/pause', [SystemController::class, 'pause'])->name('schema.pause');
            Route::post('schema/resume', [SystemController::class, 'resume'])->name('schema.resume');

            Route::get('firms', [SystemController::class, 'firms'])->name('firms.index');
            Route::post('firms', [SystemController::class, 'storeFirm'])->name('firms.store');
            Route::put('firms/{firm}', [SystemController::class, 'updateFirm'])->name('firms.update');

            Route::get('roles', [SystemController::class, 'roles'])->name('roles.index');
            Route::post('roles', [SystemController::class, 'storeRole'])->name('roles.store');
            Route::put('roles/{role}', [SystemController::class, 'updateRole'])->name('roles.update');

            Route::get('users', [SystemController::class, 'users'])->name('users.index');
            Route::post('users', [SystemController::class, 'storeUser'])->name('users.store');
            Route::put('users/{user}', [SystemController::class, 'updateUser'])->name('users.update');
            Route::delete('users/{user}/devices/{token}', [SystemController::class, 'revokeDevice'])
                ->whereNumber('token')->name('users.devices.revoke');
        });
    });
});
