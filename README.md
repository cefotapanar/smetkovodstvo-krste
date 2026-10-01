# Сметководство КРСТЕ

Сметководствен софтвер за нашата фирма, што го води ТИА Конто.
Документите ги влече од ЕРП-от „Управник КРСТЕ“; се работи од локална
Windows апликација преку `/api/v1`.

- План и одлуки: [../SMETKOVODSTVO-PLAN.md](../SMETKOVODSTVO-PLAN.md)
- Договор за API-то: [API.md](API.md)
- Упатство за работа врз кодот: [CLAUDE.md](CLAUDE.md)

## Прво пуштање на Plesk

1. Поддомен (пр. `smetkovodstvo.krste.mk`) со document root `public/`, PHP 8.2.
2. Нова MySQL база (utf8mb4) и корисник.
3. Git деплој од ова репо.
4. `.env` (од `.env.example`): `APP_KEY` (локално: `php artisan key:generate --show`), `APP_URL`, `APP_ENV=production`,
   `APP_DEBUG=false`, базата, `CLEAR_CACHE_KEY` (долг, случаен).
5. `https://<домен>/sistem.php` → клуч → „Изврши ги миграциите“ → прв главен
   администратор → прва фирма.
6. `https://<домен>/clear-cache.php?key=<CLEAR_CACHE_KEY>`.

## Локално

```
composer install            # само ако vendor/ фали — инаку е во git
php artisan migrate
php artisan serve --port=8010
php artisan test
```
