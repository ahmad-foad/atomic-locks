# Atomic Locks in Laravel: Stop Overselling

**Problem:** A concert has **1 ticket left**. 50 fans press "Buy" at the same second.
Each request reads `tickets_left = 1`, passes the `if ($tickets_left > 0)` check, and sells a ticket.
This **race condition** ("check-then-act") sells the same seat many times.

**Solution:** Laravel's `Cache::lock()` makes the check and the sale run **one request at a time**:

```php
$lock = Cache::lock("event:{$event->id}:tickets", 10);    // auto-expires after 10s

return $lock->block(5, function () use ($event, $buyer) {  // wait in line, max 5s
    $event->refresh();                                       // read the REAL stock
    return $this->sell($event, $buyer);                      // check + sell safely
});                                                          // released automatically
```

| Situation | Answer |
|---|---|
| Stock available, got the lock | `201` ticket bought |
| Got the lock, stock is 0 | `409` sold out |
| Waited more than 5s for the lock | `503` + `Retry-After: 1` |
| Process crashes while holding the lock | Lock expires after 10s (no deadlock) |

Works with the `database`, `redis`, `memcached`, `dynamodb`, `file` and `array` cache drivers.
With Redis it can protect **many servers** behind a load balancer (a distributed lock).

## A second solution: a PostgreSQL row lock

PostgreSQL can lock a single **row** until the transaction ends (SQLite cannot):

```php
return DB::transaction(function () use ($event, $buyer) {
    $locked = Event::whereKey($event->id)->lockForUpdate()->firstOrFail(); // SELECT ... FOR UPDATE
    return $this->sell($locked, $buyer);                                    // check + sell safely
});                                                                         // COMMIT releases the row
```

| | `Cache::lock` | `lockForUpdate()` |
|---|---|---|
| What is locked | a **name** (`event:1:tickets`), any resource | one **database row** |
| Works across | servers, queues, APIs, files | only data in the same database |
| Waiting | `block(5)` → `503` after 5 s | waits inside PostgreSQL (`lock_timeout`) |
| Needs | any lock-capable cache store | PostgreSQL / MySQL (not SQLite) |

## Requirements

PHP 8.3+ with `pdo_pgsql`, Composer, PostgreSQL (tested with PostgreSQL 18 on Windows 11, PHP 8.4).
`CACHE_STORE=database`, so `Cache::lock` stores its locks in the `cache_locks` table in PostgreSQL.

## Run it

Step-by-step guide with every command to paste: [RUN.md](RUN.md).

```powershell
composer install
copy .env.example .env          # then set DB_USERNAME / DB_PASSWORD
php artisan key:generate
```

Create the database user and the two databases once (as the `postgres` superuser):

```sql
CREATE ROLE your_db_user LOGIN PASSWORD 'choose-a-password';
CREATE DATABASE atomic_locks      OWNER your_db_user;   -- used by the app
CREATE DATABASE atomic_locks_test OWNER your_db_user;   -- used by php artisan test (see phpunit.xml)
```

```powershell
php artisan migrate
php artisan test                # 9 feature tests, run against PostgreSQL
php demo/serve.php              # terminal 1: 10 PHP servers on ports 8000-8009
php demo/demo.php               # terminal 2: live demo (1 ticket, 50 buyers)
```

Other scenario (PowerShell): `$env:SERVERS=20; php demo/serve.php`, then
`$env:SERVERS=20; $env:TICKETS=10; php demo/demo.php`.

**Why `demo/serve.php`?** PHP's built-in server handles one request at a time, so the buyers never
overlap and the race cannot appear. `serve.php` starts several servers and `demo.php` spreads the
50 buyers over them. It works on Windows, where `PHP_CLI_SERVER_WORKERS` is not supported. On
Linux/macOS you can use instead: `PHP_CLI_SERVER_WORKERS=10 php artisan serve --no-reload`
(`--no-reload` is required) and `SERVERS=1 php demo/demo.php`.

## Swagger (OpenAPI 3)

Step-by-step guide: [SWAGGER.md](SWAGGER.md).

Open **http://127.0.0.1:8000/api/documentation** while the server runs. Every route is documented
with PHP attributes (`OpenApi\Attributes`), including the `201`, `404`, `409`, `422` and
`503` + `Retry-After` responses with examples. Start with **Try it out** on `POST /api/demo/reset`
to get an `event_id`, then buy with each of the three routes.

## Demo output (real runs, PostgreSQL 18)

```
 1 ticket(s) left, 50 fans press "Buy" at the same second   (10 servers)

❌ BEFORE (no lock)       Responses: 10 × 201 bought, 40 × 409 sold out
   Tickets sold: 10   Tickets left: -9   (2.73s)

✅ AFTER  (Cache::lock)   Responses: 1 × 201 bought, 49 × 409 sold out
   Tickets sold: 1    Tickets left: 0    (3.79s)

✅ AFTER  (lockForUpdate) Responses: 1 × 201 bought, 49 × 409 sold out
   Tickets sold: 1    Tickets left: 0    (2.63s)
```

10 tickets, 50 buyers, 20 servers: no lock **20 sold (stock -10)**, `Cache::lock` 10 sold (stock 0,
6.56 s, one buyer got `503` after waiting 5 s), `lockForUpdate` 10 sold (stock 0, 5.20 s).

With 10 tickets and only 10 servers the unlocked route sold exactly 10: at most 10 requests run at
once, and `decrement()` is an atomic SQL `UPDATE`, so overselling needs **more parallel buyers than
tickets**. That is why the 10-ticket scenario uses 20 servers.

**Trade-off:** the locked versions are slower (requests wait in line), but they are **correct**.

## Endpoints

| Method | URL | Notes |
|---|---|---|
| POST | `/api/unsafe/events/{id}/buy` | ❌ no lock, body `{"buyer":"Ali"}` |
| POST | `/api/events/{id}/buy` | ✅ `Cache::lock` |
| POST | `/api/row-lock/events/{id}/buy` | ✅ `DB::transaction` + `lockForUpdate()` |
| GET | `/api/events/{id}` | stock + buyers |
| POST | `/api/demo/reset` | body `{"tickets":1}` → new event |

All endpoints can be tried in Swagger UI at `/api/documentation`.

## Key files

- `app/Http/Controllers/TicketController.php`: `buyUnsafe()` vs `buySafe()` vs `buyRowLock()` (same business logic, only the lock differs)
- `config/tickets.php`: payment delay and lock wait time
- `tests/Feature/TicketLockTest.php`: reproduces the race deterministically and proves the lock fixes it
- `demo/demo.php`: 50 parallel buyers using `curl_multi`, compares no lock, `Cache::lock` and `lockForUpdate`
- `demo/serve.php`: starts several PHP servers so the buyers really run in parallel
- `app/OpenApi/SharedComponents.php` + controller attributes: the Swagger documentation
