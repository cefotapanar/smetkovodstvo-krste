<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 | ПРИВРЕМЕНО — го зема состојбот на работната табла од серверот, за да се
 | прочита во разговорот што одговорила сметководителката.
 | Бара PROJEKT_REMOTE_KEY во локалниот .env (= PROJEKT_KEY на серверот).
 */
Artisan::command('projekt:povleci', function () {
    $key = (string) config('projekt.remote_key');
    if ($key === '') {
        $this->error('Нема PROJEKT_REMOTE_KEY во .env.');

        return 1;
    }

    $r = Http::withHeaders(['X-Projekt-Key' => $key, 'Accept' => 'application/json'])
        ->timeout(30)->get(rtrim((string) config('projekt.remote_url'), '/').'/projekt/izvoz');

    if (! $r->ok()) {
        $this->error('Серверот врати '.$r->status().'.');

        return 1;
    }

    $path = storage_path('app/projekt-od-serverot.json');
    file_put_contents($path, $r->body());

    $items = collect($r->json('items'));
    $this->info('Зачувано: '.$path);
    $this->line('Отворени: '.$items->where('status', 'open')->count().' · одлучени: '.$items->where('status', 'decided')->count()
        .' · коментари: '.$items->sum(fn ($i) => count($i['comments'])));

    return 0;
})->purpose('Работната табла од серверот → storage/app/projekt-od-serverot.json');
