<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Токен на локалната апликација — Sanctum-овиот модел плус `last_ip` и
 * `app_version` (ги полни `TouchApiToken`), за списокот на пријавени уреди.
 * Регистриран во AppServiceProvider.
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';
}
