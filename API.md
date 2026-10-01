# API на Сметководство КРСТЕ — договор

Ова е **единственото место каде серверот и локалната апликација се договараат.**
Нова рута прво се запишува тука, па дури потоа се пишува кодот.

- План и фази: [../SMETKOVODSTVO-PLAN.md](../SMETKOVODSTVO-PLAN.md)
- Код на серверот: `routes/api.php`, `app/Http/Controllers/Api/`
- Код на клиентот: `lokalna-app/` (фаза 3)

Состојба: **фаза 2** — јадро: контен план, партнери, налози (книжење, сторно),
периоди, отворени ставки, бруто биланс, картички, дневник, главна книга.
Сервер 0.2.0

---

## 1. Правила што важат за сè

1. **Серверот е мозок, апликацијата е уста и очи.** Салдо, збир, ДДВ, курс,
   реден број на налог — сè смета серверот. Апликацијата прикажува готови броеви.
2. **Секое барање во рамки на фирма носи `X-Firm`.** Нема „стандардна фирма“ —
   книжење во погрешна фирма е најскапата грешка, па серверот одбива да
   погодува. Дозволите се по фирма (улогата е врзана за паровот корисник–фирма).
3. **100% онлајн.** Нема локална база ни ред за качување.
4. **Прокнижено не се брише ниту менува** — само сторно (409 `locked`).

---

## 2. Основа

| | |
|---|---|
| Основна адреса | `https://smetkovodstvo.krste.mk/api/v1/` (локално `http://127.0.0.1:8010/api/v1/`) |
| Формат | JSON, `snake_case`, датуми `YYYY-MM-DD`, пари како број со 2 децимали |
| Најава | `Authorization: Bearer <токен>` (Sanctum, без истек; одземање рачно) |
| Верзија на апликацијата | `X-App-Version: 0.1.0` — постара од `local_app_min` добива 426 |
| Фирма | `X-Firm: <id>` — за сите рути означени со 🏢 |
| Секогаш | `Accept: application/json` |

### Грешки

Секоја грешка е JSON `{ "error": "<код>", "message": "<реченица на македонски>" }`.

| HTTP | `error` | Кога |
|---|---|---|
| 400 | `firm_required` | рута 🏢 без `X-Firm` |
| 401 | `unauthenticated` | нема токен, или е одземен |
| 401 | `invalid_credentials` | погрешна е-пошта или лозинка при најава |
| 403 | `forbidden` | улогата не дозволува (преглед или запишување) |
| 403 | `firm_forbidden` | корисникот нема пристап до таа фирма (или фирмата не постои — не се кажува што од двете) |
| 403 | `inactive` | корисникот е деактивиран |
| 404 | `not_found` | записот не постои |
| 409 | `locked` | прокнижен налог (бришење, измена, повторно книжење), втор сторно |
| 422 | `validation` | + `errors: {поле: [пораки]}`. Правилата на книжењето (Д ≠ П, заклучен период, конто без партнер…) се исто 422, клучот е `lines.N.поле`, `lines` или `date` |
| 429 | — | повеќе од 10 обиди за најава во минута |
| 426 | `client_outdated` | + `need`, `have`, `latest` |
| 503 | `schema_outdated` | базата заостанува зад кодот; читањето поминува, запишувањето не |

---

## 3. Рути

### 3.1 Без најава

#### `GET ping`

```json
{ "ok": true, "server": { "version": "0.1.0", "api_version": "v1",
  "local_app": "0.1.0", "local_app_min": "0.1.0", "time": "2026-10-01T10:00:00+02:00" } }
```

Евтин, не ја допира базата. Апликацијата го вика на неколку секунди.

#### `POST login`

Барање: `{ "email", "password", "device_name" }`
Одговор: `{ "token", "user": <профил>, "firms": [<фирма>…], "server": {…} }`

Повторна најава од ист уред (`device_name`) го брише стариот токен.

### 3.2 Со најава

#### `GET me` → `{ "user", "firms", "server" }`

**Профил:**

```json
{ "id": 1, "name": "…", "email": "…", "is_super": false }
```

**Фирма** (во `firms` — само фирмите до кои корисникот има пристап; главниот
администратор ги гледа сите):

```json
{ "id": 1, "name": "…", "short_name": "…", "tax_id": "40…", "reg_no": "…",
  "vat_period": "month", "is_active": true,
  "role": "Сметководител", "permissions": ["journal", "journal.write", "cards", "…"] }
```

`permissions` е рамна листа клучеви (§4) — менито во апликацијата се гради од неа.

#### `POST logout`

Го брише САМО токенот со кој е направен повикот.

#### 🏢 `GET firm`

Тековната фирма (од `X-Firm`) во истиот облик како во `firms`. Апликацијата
го вика по избор на фирма — потврда дека изборот важи.

### 3.3 Систем — само главен администратор (`is_super`)

Не е по фирма, нема `X-Firm`.

| Рута | Што |
|---|---|
| `GET system/schema` | состојба на базата: `pending`, `ahead`, `applied` (број), `head`, `guard_on`, `paused_till`, `database`, `db_guard` (дали тригерите што ги чуваат прокнижените налози постојат) |
| `POST system/schema/migrate` | ги извршува миграциите (со заклучување против двојно пуштање) → `{ ok, message, output }` |
| `POST system/schema/pause` · `resume` | стражарот 30 мин. исклучен / пак вклучен |
| `GET system/firms` | сите фирми |
| `POST system/firms` | нова фирма: `name`*, `short_name`, `tax_id`* (ЕДБ, 13 цифри), `reg_no` (ЕМБС), `activity_code` (НКД), `size` (`micro`/`small`/`medium`/`large`), `vat_period`* (`month`/`quarter`), `address`, `is_active` |
| `PUT system/firms/{id}` | измена, истите полиња |
| `GET system/roles` | улоги + каталог на дозволи (`catalog`: групи → клуч → натпис, `readonly`: клучеви без „.write“) |
| `POST system/roles` · `PUT system/roles/{id}` | `name`*, `permissions` (листа клучеви од каталогот) |
| `GET system/users` | корисници со нивните фирми и улоги, и `devices` (пријавени уреди: `id`, `name`, `last_used_at`, `last_ip`, `app_version`) |
| `POST system/users` | `name`*, `email`*, `password`* (најмалку 10 знаци), `is_super`, `is_active`, `firms`: `[{ "firm_id", "role_id" }]` |
| `PUT system/users/{id}` | исто; `password` незадолжителна (празно = не се менува). Деактивирање или нова лозинка ги брише токените на корисникот. |
| `DELETE system/users/{id}/devices/{token_id}` | одземање пристап на еден уред |

Сам себеси главниот администратор не може да се деактивира ни да си го одземе
`is_super` — инаку системот останува без никој што може да го врати.

### 3.4 Шифрарници 🏢

#### Конто

```json
{ "id": 7, "code": "1200", "name": "Купувачи — земја", "level": "analytic",
  "is_statutory": false, "is_postable": true, "needs_partner": true,
  "needs_cost_center": false, "is_active": true, "used": true }
```

`level`: `class` (1 цифра), `group` (2), `synthetic` (3), `analytic` (4–8).
Класите, групите и синтетиката (`is_statutory`) доаѓаат од Правилникот 174/2011
при создавање на фирмата. **Се книжи само на `is_postable`**: конто од 3+ цифри
без подконта.

| Рута | Дозвола | Што |
|---|---|---|
| `GET accounts?q=&postable=1&active=1` | `accounts` | цел план (без страници); `q` бара во шифра и име |
| `POST accounts` | `accounts.write` | аналитика: `code`* (4–8 цифри, почнува со постоечка синтетика), `name`*, `needs_partner`, `needs_cost_center` (стандардно од родителот). Одбива ако родителот веќе има книжење — салдото би останало на конто што повеќе не се книжи. |
| `PUT accounts/{id}` | `accounts.write` | `name` (не за законските), `needs_partner`, `needs_cost_center`, `is_active`. Шифрата не се менува никогаш. |
| `DELETE accounts/{id}` | `accounts.write` | само аналитика без книжење и без подконта |

#### Партнер

```json
{ "id": 3, "name": "…", "tax_id": "4030…", "address": "…", "city": "…",
  "country": "MK", "erp_customer_id": null, "is_active": true, "used": false }
```

| Рута | Дозвола | Што |
|---|---|---|
| `GET partners?q=&page=` | `partners` | 50 по страница, `meta: {page, per_page, total, last_page}` |
| `POST partners` · `PUT partners/{id}` | `partners.write` | `name`*, `tax_id` (13 цифри, единствен во фирмата), `address`, `city`, `country` (2 букви), `is_active` |
| `DELETE partners/{id}` | `partners.write` | само без книжење |

#### Место на трошок — `{ id, code, name, is_active }`

`GET cost-centers` (`accounts`), `POST` · `PUT cost-centers/{id}` · `DELETE cost-centers/{id}` (`accounts.write`; бришење само без книжење).

#### Видови налози — `GET journal-types` (`journal`)

`[{ "id", "code": "ИФ", "name": "Излезни фактури", "is_opening": false }]` —
ПС (почетна состојба, `is_opening`), ИФ, ВФ, ИЗ, БЛ, КЛ, ОС, ПЛ, РН (рачен), ЗТ (затворање).

### 3.5 Налози за книжење 🏢 (`journal`)

```json
{ "id": 41, "type": { "id": 2, "code": "ИФ", "name": "Излезни фактури" },
  "number": 12, "label": "ИФ-12/2027", "date": "2027-01-14", "year": 2027,
  "description": "…", "status": "posted", "posting_seq": 118,
  "posted_at": "…", "posted_by": "…", "created_by": "…",
  "storno_of_id": null, "stornoed_by_id": null,
  "total_debit": 12390.00, "total_credit": 12390.00, "balanced": true,
  "lines": [
    { "id": 1, "line_no": 1, "account": { "id": 7, "code": "1200", "name": "…" },
      "partner": { "id": 3, "name": "…" }, "cost_center": null,
      "doc_number": "Ф-123", "doc_date": "2027-01-14", "due_date": "2027-02-13",
      "debit": 12390.00, "credit": 0, "description": "…" } ] }
```

- **Нацрт** (`draft`): нема број, `label` = „Нацрт #41“. Може да не е во рамнотежа. Се менува и брише.
- **Прокнижен** (`posted`): добива `number` (по вид и година) и `posting_seq`
  (реден број во дневникот, по фирма и година) — **без дупки**, во моментот на
  книжење. Не се менува, не се брише.
- **Сторно** е „црвено“: нов прокнижен налог со ИСТИТЕ конта и страни и
  **негативни** износи. Прометот не се надува, а салдото се враќа.

| Рута | Што |
|---|---|
| `GET journal?status=&type=&from=&to=&q=&page=` | список без ставки (50 по страница) |
| `GET journal/{id}` | налог со ставки |
| `POST journal` | нов нацрт: `journal_type_id`*, `date`*, `description`, `lines`: `[{ account_id*, partner_id, cost_center_id, doc_number, doc_date, due_date, debit, credit, description }]`. Со `"post": true` — зачувај и прокнижи атомски. |
| `PUT journal/{id}` | измена на нацрт (ставките се заменуваат цели); прокнижен → 409 |
| `DELETE journal/{id}` | само нацрт; прокнижен → 409 |
| `POST journal/{id}/post` | книжење |
| `POST journal/{id}/storno` | `{ date, description }` — `date` стандардно е датумот на оригиналот; ако тој период е заклучен, мора да се даде датум во отворен период. Втор сторно или сторно на сторно → 409. |

**Правила при книжење** (422): најмалку две ставки; секоја ставка има ИЛИ должи
ИЛИ побарува (не двете, не нула); Σ должи = Σ побарува до стотинка; контото е
активно и за книжење; партнер кога контото го бара; место на трошок кога го
бара; датумот не е во заклучен период. Нацрт во заклучен период не се ни зачувува.

### 3.6 Периоди 🏢 (`closing`)

| Рута | Што |
|---|---|
| `GET periods?year=2027` | `[{ month, locked, locked_at, locked_by }]` × 12 |
| `POST periods/lock` · `POST periods/unlock` | `{ year, month }`. Во заклучен месец нема книжење ни сторно со датум во него. |

### 3.7 Отворени ставки 🏢

Ставка = прокнижена ставка на конто што бара партнер. Знак: `amount` = должи − побарува
(сторно ставка има спротивен знак). Се затвораат две ставки на ИСТО конто и
ИСТ партнер со спротивни знаци.

| Рута | Дозвола | Што |
|---|---|---|
| `GET open-items?partner_id=*&account_id=&all=` | `cards` | `[{ line_id, label, date, account, doc_number, due_date, amount, matched, remaining }]` — без `all` само `remaining ≠ 0` |
| `POST open-items/match` | `journal.write` | `{ line_id, other_line_id, amount }` — `amount` стандардно колку што може (помалиот остаток) |
| `DELETE open-items/match/{id}` | `journal.write` | отворање |

Сторно автоматски ги отвора затворањата на оригиналот и го затвора оригиналот
со својата сторно ставка.

### 3.8 Извештаи и картички 🏢

Периодот е во рамки на една година (`from`, `to`; стандардно од 1 јануари до денес).
**Почетна состојба** = налози од вид ПС + сè во таа година пред `from`.
**Промет** = сè друго во `from`–`to`.

| Рута | Дозвола | Што |
|---|---|---|
| `GET reports/trial-balance?from=&to=&level=` | `reports` | бруто биланс. `level`: `analytic` (стандард), `synthetic`, `group`, `class`. Редови: `code, name, level, opening_debit, opening_credit, debit, credit, total_debit, total_credit, balance_debit, balance_credit`; плус `totals` во ист облик |
| `GET reports/journal-book?from=&to=` | `reports` | дневник: прокнижени налози по `posting_seq`, со ставки |
| `GET reports/general-ledger?from=&to=&account_id=` | `reports` | главна книга: по конто — почетна, ставки, промет, салдо |
| `GET reports/integrity` | `reports` | `{ chain_ok, checked, broken_at, db_guard }` — синџирот на хешови на прокнижените налози и дали заштитата во базата (тригери) постои |
| `GET cards/account/{id}?from=&to=&partner_id=` | `cards` | `{ account, opening: {debit, credit, balance}, lines: [{ entry_id, label, posting_seq, date, doc_number, partner, description, debit, credit, balance }], totals }`. За синтетика ги опфаќа и подконтата. |
| `GET cards/partner/{id}?from=&to=&account_id=` | `cards` | истото по партнер; секој ред носи и `account` |

---

## 4. Каталог на дозволи

| Група | Клуч | Што | Само преглед |
|---|---|---|---|
| Книговодство | `journal` | Налози за книжење | |
| | `inbox` | Сандаче (документи од ЕРП) | |
| | `accounts` | Контен план | |
| | `partners` | Партнери | |
| Прегледи | `cards` | Картички (конто, партнер) | ✓ |
| | `reports` | Бруто биланс, дневник, главна книга | ✓ |
| ДДВ | `vat` | КИФ, КПФ, ДДВ-04 | |
| Средства | `fixed_assets` | Основни средства | |
| Затворање | `closing` | Заклучување на период и година | |

Запишувањето е посебен клуч `<клуч>.write`. `POST/PUT/PATCH/DELETE` бараат
`.write`; `GET` бара само клучот.
