<?php

/*
 | Систем без најава — за ПРВОТО пуштање на Plesk и за итни случаи.
 |
 | Зошто постои: на Plesk нема SSH, а пред првата миграција нема ниту табела
 | `users`, ниту корисник што би се најавил во апликацијата за да ја пушти
 | миграцијата. Кокошка и јајце. Оваа страница го прекинува кругот:
 |   1. ги извршува миграциите,
 |   2. го прави ПРВИОТ главен администратор (само ако нема ниту еден корисник),
 |   3. ја прави ПРВАТА фирма (само ако нема ниту една).
 | Сè понатаму оди од апликацијата (Систем).
 |
 | Клучот е CLEAR_CACHE_KEY од .env и се праќа со POST, НЕ во адресата —
 | адресите остануваат во дневниците на веб-серверот.
 |
 | Употреба: https://smetkovodstvo.krste.mk/sistem.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\System\SaveFirm;
use App\Actions\System\SaveUser;
use App\Actions\System\SchemaStatus;
use App\Http\Requests\System\FirmRequest;
use App\Http\Requests\System\UserRequest;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$key = (string) config('app.clear_cache_key');
if ($key === '') {
    http_response_code(403);
    exit('Не е поставен CLEAR_CACHE_KEY во .env — страницата е заклучена.');
}

$sent = (string) ($_POST['key'] ?? '');
$authorized = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($key, $sent);

$messages = [];
$errors = [];
$output = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $authorized) {
    // Мало задоцнување: клучот не се погодува со илјадници обиди во минута.
    usleep(800000);
    $errors[] = 'Погрешен клуч.';
}

$schema = app(SchemaStatus::class);

if ($authorized) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'migrate') {
            $r = $schema->migrate();
            if ($r['ok']) {
                $messages[] = $r['message'];
            } else {
                $errors[] = $r['message'];
            }
            $output = $r['output'];
        } elseif ($action === 'admin') {
            if (! Schema::hasTable('users')) {
                $errors[] = 'Прво извршете ги миграциите — уште нема табела за корисници.';
            } elseif (User::exists()) {
                $errors[] = 'Првиот администратор веќе постои — понатамошните корисници се прават од апликацијата.';
            } else {
                $data = Validator::make($_POST, UserRequest::rulesFor(), [], UserRequest::names())->validate();
                app(SaveUser::class)->run([
                    'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                    'is_super' => true, 'is_active' => true,
                ]);
                $messages[] = 'Главниот администратор е создаден. Сега направете ја фирмата подолу, па најавете се на почетната страница.';
            }
        } elseif ($action === 'firm') {
            if (! Schema::hasTable('firms')) {
                $errors[] = 'Прво извршете ги миграциите — уште нема табела за фирми.';
            } elseif (Firm::exists()) {
                $errors[] = 'Фирма веќе постои — понатамошните се прават од апликацијата.';
            } else {
                $data = Validator::make($_POST, FirmRequest::rulesFor(), ['tax_id.regex' => 'ЕДБ мора да има точно 13 цифри.'], FirmRequest::names())->validate();
                app(SaveFirm::class)->run($data);
                $messages[] = 'Фирмата е создадена.';
            }
        }
    } catch (ValidationException $e) {
        foreach ($e->errors() as $list) {
            foreach ($list as $m) {
                $errors[] = $m;
            }
        }
    } catch (\Throwable $e) {
        report($e);
        $errors[] = 'Грешка: '.$e->getMessage();
    }
}

$status = $authorized ? $schema->status() : null;
$hasUsers = $authorized && Schema::hasTable('users') && User::exists();
$hasFirms = $authorized && Schema::hasTable('firms') && Firm::exists();
?>
<!doctype html>
<html lang="mk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Систем — <?= h(config('app.name')) ?></title>
<style>
  body { font: 15px/1.5 system-ui, "Segoe UI", sans-serif; max-width: 760px; margin: 24px auto; padding: 0 16px; color: #1d2733; background: #f6f7f9; }
  h1 { font-size: 20px; } h2 { font-size: 16px; margin-top: 28px; }
  .box { background: #fff; border: 1px solid #d9dee5; border-radius: 6px; padding: 14px 16px; margin: 12px 0; }
  .ok { border-color: #8bc79a; background: #effaf1; } .err { border-color: #e39a9a; background: #fdf0f0; }
  label { display: inline-block; margin: 4px 12px 4px 0; } input, select { font: inherit; padding: 4px 6px; }
  button { font: inherit; padding: 6px 14px; cursor: pointer; }
  pre { white-space: pre-wrap; font-size: 13px; background: #f0f2f5; padding: 8px; }
  td { padding: 2px 12px 2px 0; vertical-align: top; }
</style>
</head>
<body>
<h1><?= h(config('app.name')) ?> · сервер <?= h(config('version.number')) ?></h1>

<?php foreach ($messages as $m): ?><div class="box ok"><?= h($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $m): ?><div class="box err"><?= h($m) ?></div><?php endforeach; ?>

<?php if (! $authorized): ?>
  <form method="post" class="box">
    <label>Клуч (CLEAR_CACHE_KEY): <input type="password" name="key" autofocus size="40"></label>
    <button>Влез</button>
  </form>
<?php else: ?>
  <div class="box">
    <table>
      <tr><td>База</td><td><?= h($status['database']) ?></td></tr>
      <tr><td>Извршени миграции</td><td><?= count($status['applied']) ?> од <?= count($status['expected']) ?></td></tr>
      <tr><td>Стражар</td><td><?= $status['guardOn'] ? 'вклучен' : 'ИСКЛУЧЕН (SCHEMA_GUARD=false)' ?></td></tr>
      <?php /* Без тригерите прокнижените налози ги чува само кодот — види LedgerGuard. */ ?>
      <tr><td>Заштита на книгите во базата</td><td><?= ! Schema::hasTable('journal_entries')
          ? '— (се проверува по миграциите)'
          : (App\Support\LedgerGuard::isInstalled()
          ? 'да (тригери)'
          : '<b>НЕМА</b> — тригерите не се создадени (на Plesk најчесто треба право SUPER или log_bin_trust_function_creators=1). Прокнижените налози ги чува само кодот.') ?></td></tr>
      <?php if ($status['error']): ?><tr><td>Грешка</td><td><?= h($status['error']) ?></td></tr><?php endif; ?>
    </table>
  </div>

  <?php if ($status['pending']): ?>
    <h2>Неизвршени миграции (<?= count($status['pending']) ?>)</h2>
    <div class="box"><pre><?= h(implode("\n", $status['pending'])) ?></pre>
      <form method="post">
        <input type="hidden" name="key" value="<?= h($sent) ?>">
        <button name="action" value="migrate">Изврши ги миграциите</button>
      </form>
    </div>
  <?php else: ?>
    <div class="box ok">Базата е ажурирана со кодот.</div>
  <?php endif; ?>
  <?php if ($output !== ''): ?><pre><?= h($output) ?></pre><?php endif; ?>

  <?php if (! $status['pending'] && ! $hasUsers): ?>
    <h2>Прв главен администратор</h2>
    <form method="post" class="box">
      <input type="hidden" name="key" value="<?= h($sent) ?>">
      <label>Име <input name="name" required></label>
      <label>Е-пошта <input name="email" type="email" required></label>
      <label>Лозинка (најмалку 10 знаци) <input name="password" type="password" required minlength="10"></label>
      <button name="action" value="admin">Создај</button>
    </form>
  <?php endif; ?>

  <?php if (! $status['pending'] && $hasUsers && ! $hasFirms): ?>
    <h2>Прва фирма</h2>
    <form method="post" class="box">
      <input type="hidden" name="key" value="<?= h($sent) ?>">
      <label>Име <input name="name" required size="40"></label>
      <label>ЕДБ <input name="tax_id" required pattern="\d{13}" size="15"></label>
      <label>ЕМБС <input name="reg_no" size="10"></label>
      <label>Дејност (НКД) <input name="activity_code" size="8"></label>
      <label>ДДВ период
        <select name="vat_period"><option value="month">месечен</option><option value="quarter">тримесечен</option></select>
      </label>
      <button name="action" value="firm">Создај</button>
    </form>
  <?php endif; ?>

  <?php if ($hasUsers && $hasFirms && ! $status['pending']): ?>
    <div class="box">Сè е подготвено. Пуштете го clear-cache.php, па најавете се на <a href="/">почетната страница</a>.</div>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
