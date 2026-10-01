<?php

/*
 | ПРИВРЕМЕНО — работната табла на проектот (страницата `/`).
 |
 | PROJEKT_KEY        — на серверот: клуч за `/projekt/izvoz` (JSON за разговорот
 |                      со Claude). Празно = извозот не постои.
 | PROJEKT_REMOTE_URL — локално: од каде `php artisan projekt:povleci` влече.
 | PROJEKT_REMOTE_KEY — локално: истиот клуч како PROJEKT_KEY на серверот.
 */
return [
    'key'        => env('PROJEKT_KEY'),
    'remote_url' => env('PROJEKT_REMOTE_URL', 'https://smetkovodstvo.krste.mk'),
    'remote_key' => env('PROJEKT_REMOTE_KEY'),
];
