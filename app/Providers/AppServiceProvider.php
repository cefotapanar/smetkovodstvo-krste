<?php

namespace App\Providers;

use App\Models\ApiToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Нашиот модел на токен ги носи и `last_ip` / `app_version`.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
    }
}
