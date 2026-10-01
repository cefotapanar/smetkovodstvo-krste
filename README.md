# Сметководство КРСТЕ

Сметководствен софтвер за нашата фирма, што го води ТИА Конто.
Документите ги влече од ЕРП-от „Управник КРСТЕ“; се работи од локална
Windows апликација преку `/api/v1`.

- План и одлуки: [../SMETKOVODSTVO-PLAN.md](../SMETKOVODSTVO-PLAN.md)
- Договор за API-то: [API.md](API.md)
- Упатство за работа врз кодот: [CLAUDE.md](CLAUDE.md)

## Прво пуштање на Plesk

Поддоменот `smetkovodstvo.krste.mk` е направен; document root моментално е
`smetkovodstvo.krste.mk`.

1. **Git** (Websites & Domains → smetkovodstvo.krste.mk → Git): додај го
   репото `https://github.com/cefotapanar/smetkovodstvo-krste.git` — исто како
   ЕРП-от (приватно репо: SSH адреса `git@github.com:cefotapanar/smetkovodstvo-krste.git`,
   а клучот што го покажува Plesk оди во GitHub → репото → Settings → Deploy keys).
   Патека за деплој: **`/smetkovodstvo.krste.mk`**, автоматски деплој вклучен.
   Composer НЕ треба — `vendor/` е во репото.
2. **Hosting Settings → Document root: `smetkovodstvo.krste.mk/public`**.
   Без ова се гледа целиот код наместо апликацијата.
3. **PHP Settings**: 8.2 или понова.
4. **Databases → Add Database**: на пр. `smetk_db`, utf8mb4, свој корисник.
5. **SSL** (Let's Encrypt) за поддоменот.
6. **`.env`** во `smetkovodstvo.krste.mk/` (File Manager), од `.env.example`:
   ```
   APP_NAME="Сметководство КРСТЕ"
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:...            ← локално: php artisan key:generate --show
   APP_URL=https://smetkovodstvo.krste.mk
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   CLEAR_CACHE_KEY=...           ← долг случаен текст
   PROJEKT_KEY=...               ← друг долг случаен текст (за projekt:povleci)
   ```
   Случаен текст: `php -r "echo bin2hex(random_bytes(24));"`
7. **`https://smetkovodstvo.krste.mk/sistem.php`** → клучот (CLEAR_CACHE_KEY) →
   „Изврши ги миграциите“ → прв главен администратор → прва фирма (вистинското
   име и ЕДБ). Провери го редот **„Заштита на книгите во базата“** — ако пишува
   НЕМА, кажи (хостингот не дозволува тригери; книгите ги чува само кодот).
8. **`https://smetkovodstvo.krste.mk/clear-cache.php?key=<CLEAR_CACHE_KEY>`**.
9. **`https://smetkovodstvo.krste.mk`** → најава → долу десно „Пристап“ →
   сметководителката (страна: Сметководител). Лозинката кажи ја лично.

По секој следен `git pull`: миграциите (ако ги има) од `sistem.php`, па `clear-cache.php`.

## Локално

```
composer install            # само ако vendor/ фали — инаку е во git
php artisan migrate
php artisan serve --port=8010
php artisan test
```
