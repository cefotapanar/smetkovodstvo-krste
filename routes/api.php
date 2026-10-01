<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\LedgerController;
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

            Route::middleware('permission:accounts')->group(function () {
                Route::get('accounts', [CatalogController::class, 'accounts'])->name('accounts.index');
                Route::post('accounts', [CatalogController::class, 'storeAccount'])->name('accounts.store');
                Route::put('accounts/{id}', [CatalogController::class, 'updateAccount'])->whereNumber('id')->name('accounts.update');
                Route::delete('accounts/{id}', [CatalogController::class, 'deleteAccount'])->whereNumber('id')->name('accounts.destroy');

                Route::get('cost-centers', [CatalogController::class, 'costCenters'])->name('cost-centers.index');
                Route::post('cost-centers', [CatalogController::class, 'storeCostCenter'])->name('cost-centers.store');
                Route::put('cost-centers/{id}', [CatalogController::class, 'updateCostCenter'])->whereNumber('id')->name('cost-centers.update');
                Route::delete('cost-centers/{id}', [CatalogController::class, 'deleteCostCenter'])->whereNumber('id')->name('cost-centers.destroy');
            });

            Route::middleware('permission:partners')->group(function () {
                Route::get('partners', [CatalogController::class, 'partners'])->name('partners.index');
                Route::post('partners', [CatalogController::class, 'storePartner'])->name('partners.store');
                Route::put('partners/{id}', [CatalogController::class, 'updatePartner'])->whereNumber('id')->name('partners.update');
                Route::delete('partners/{id}', [CatalogController::class, 'deletePartner'])->whereNumber('id')->name('partners.destroy');
            });

            Route::middleware('permission:journal')->group(function () {
                Route::get('journal-types', [CatalogController::class, 'journalTypes'])->name('journal-types.index');
                Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
                Route::get('journal/{id}', [JournalController::class, 'show'])->whereNumber('id')->name('journal.show');
                Route::post('journal', [JournalController::class, 'store'])->name('journal.store');
                Route::put('journal/{id}', [JournalController::class, 'update'])->whereNumber('id')->name('journal.update');
                Route::delete('journal/{id}', [JournalController::class, 'destroy'])->whereNumber('id')->name('journal.destroy');
                Route::post('journal/{id}/post', [JournalController::class, 'post'])->whereNumber('id')->name('journal.post');
                Route::post('journal/{id}/storno', [JournalController::class, 'storno'])->whereNumber('id')->name('journal.storno');

                Route::post('open-items/match', [LedgerController::class, 'match'])->name('open-items.match');
                Route::delete('open-items/match/{id}', [LedgerController::class, 'unmatch'])->whereNumber('id')->name('open-items.unmatch');
            });

            Route::middleware('permission:closing')->group(function () {
                Route::get('periods', [LedgerController::class, 'periods'])->name('periods.index');
                Route::post('periods/lock', [LedgerController::class, 'lock'])->name('periods.lock');
                Route::post('periods/unlock', [LedgerController::class, 'unlock'])->name('periods.unlock');
            });

            Route::middleware('permission:cards')->group(function () {
                Route::get('open-items', [LedgerController::class, 'openItems'])->name('open-items.index');
                Route::get('cards/account/{id}', [LedgerController::class, 'accountCard'])->whereNumber('id')->name('cards.account');
                Route::get('cards/partner/{id}', [LedgerController::class, 'partnerCard'])->whereNumber('id')->name('cards.partner');
            });

            Route::middleware('permission:reports')->prefix('reports')->name('reports.')->group(function () {
                Route::get('trial-balance', [LedgerController::class, 'trialBalance'])->name('trial-balance');
                Route::get('journal-book', [LedgerController::class, 'journalBook'])->name('journal-book');
                Route::get('general-ledger', [LedgerController::class, 'generalLedger'])->name('general-ledger');
                Route::get('integrity', [LedgerController::class, 'integrity'])->name('integrity');
            });
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
