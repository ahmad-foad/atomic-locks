# How to run the Atomic Locks project: step by step

Project folder: **`C:\Users\home\Downloads\atomic-locks`**

On this laptop everything is already installed and set up (PHP 8.4, Composer, PostgreSQL 18, the
databases and the tables). For the presentation you only need **Part 1**. Part 4 is for a new
computer.

Every grey box is a command: copy it, paste it into **PowerShell**, press **Enter**.

---

## Part 1: Run the demo (presentation day)

You need **two PowerShell windows**: window 1 runs the app, window 2 runs the demo.

### Step 1: Check that PostgreSQL is running

Open PowerShell (Start menu → type `PowerShell` → Enter) and paste:

```powershell
Get-Service postgresql-x64-18
```

The `Status` column must say **Running**. If it says `Stopped`, see Problem 4 in Part 3.

### Step 2: Window 1: start the app

In the same window, paste these two lines:

```powershell
cd "C:\Users\home\Downloads\atomic-locks"
php demo/serve.php
```

You should see:

```
Started 10 servers: http://127.0.0.1:8000 ... http://127.0.0.1:8009
Swagger UI: http://127.0.0.1:8000/api/documentation
Now run `php demo/demo.php` in another terminal. Press Ctrl+C to stop.
```

**Leave this window open.** The app works only while it runs. Why 10 servers: one PHP server
handles one request at a time, so the 50 buyers would never overlap and the bug would never show.

### Step 3: Open Swagger in the browser

Open this address in Chrome or Edge:

```
http://127.0.0.1:8000/api/documentation
```

To use Swagger, follow `SWAGGER-Guide.pdf` (or `SWAGGER.md`) in this folder. Short version:
`POST /api/demo/reset` → **Try it out** → **Execute** → remember the `event_id` → use it in a buy route.

### Step 4: Window 2: run the live race demo

Open a **second** PowerShell window and paste:

```powershell
cd "C:\Users\home\Downloads\atomic-locks"
php demo/demo.php
```

It sends 50 "Buy" requests at the same moment to each of the three routes and prints something like:

```
❌ BEFORE (no lock)    POST /api/unsafe/events/34/buy
   Responses:    10 × 201 bought, 40 × 409 sold out
   Tickets sold: 10   Tickets left: -9   (3.21s)

✅ AFTER  (Cache::lock)  POST /api/events/35/buy
   Responses:    1 × 201 bought, 49 × 409 sold out
   Tickets sold: 1   Tickets left: 0   (5.75s)

✅ AFTER  (lockForUpdate)  POST /api/row-lock/events/36/buy
   Responses:    1 × 201 bought, 49 × 409 sold out
   Tickets sold: 1   Tickets left: 0   (3.27s)

 RESULT: no lock 10 sold (stock -9)  |  Cache::lock 1 sold (stock 0)  |  lockForUpdate 1 sold (stock 0)
```

The number for "no lock", the event numbers and the times change from run to run (it is a race).
The locked rows are always **1 sold, 0 left**. You can run the command again as many times as you
want. The event number in the first line (here `34`) can be opened in Swagger with
`GET /api/events/{event}` to show the list of buyers.

### Step 5 (optional): the 10-ticket scenario

With 10 tickets you need **20 servers**, otherwise there are never more parallel buyers than tickets.

1. In **window 1** press **Ctrl+C** to stop the app, then paste:
   ```powershell
   $env:SERVERS=20; php demo/serve.php
   ```
2. In **window 2** paste:
   ```powershell
   $env:SERVERS=20; $env:TICKETS=10; php demo/demo.php
   ```

Our real result: no lock **20 sold (stock −10)**, both locks **10 sold (stock 0)**, and one
`Cache::lock` buyer got `503` after waiting 5 seconds.

These settings stay in that window. To go back to the normal demo, close both windows and start
again from Step 2, or paste this in each window:

```powershell
Remove-Item Env:SERVERS, Env:TICKETS -ErrorAction SilentlyContinue
```

### Step 6 (optional): run the automated tests

In window 2 (in the project folder):

```powershell
php artisan test
```

Expected last line: **9 passed**. The tests use their own database (`atomic_locks_test`), so they
do not touch the demo data.

### Step 7: Stop everything

Click **window 1** and press **Ctrl+C**. That stops all 10 (or 20) servers.

If a server is still running afterwards (for example, you closed the window with the X), paste:

```powershell
Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force
```

---

## Part 2: Switching to the other project

Both projects use **port 8000**, so only one can run at a time.

1. Stop this project first (Step 7).
2. Then start the other one from its own folder:
   ```powershell
   cd "C:\Users\home\Downloads\Laravel Projects\idempotency-keys"
   php demo/serve.php
   ```
   Its own guide is `RUN.md` in that folder.

---

## Part 3: If something goes wrong

| What you see | Why | What to do |
|---|---|---|
| `Could not open input file: demo/serve.php` (or `demo/demo.php`) | PowerShell is not in the project folder | Paste `cd "C:\Users\home\Downloads\atomic-locks"` first, then the command again |
| `Cannot reach http://127.0.0.1:8000 - is php demo/serve.php running?` | Window 1 is not running | Do Step 2 in another window, then run the demo again |
| `A server stopped (is the port already in use?)` | Something else uses port 8000, usually the other project | Stop all PHP servers (Step 7), then Step 2 again |
| `SQLSTATE[08006] ... Connection refused` | PostgreSQL is not running | Problem 4 below |
| Swagger page does not open | Window 1 is not running, or wrong address | Check window 1, use exactly `http://127.0.0.1:8000/api/documentation` |
| Swagger gives `404` for an event | That `event_id` was deleted by a newer reset | Run `POST /api/demo/reset` again and use the new `event_id` |

**Problem 4: PostgreSQL is stopped.** Open PowerShell **as administrator** (Start menu → type
`PowerShell` → right-click → *Run as administrator*) and paste:

```powershell
Start-Service postgresql-x64-18
```

---

## Part 4: Setting it up on a new computer (not needed on this laptop)

You need PHP 8.3+ with the `pdo_pgsql` extension, Composer and PostgreSQL.

1. In the project folder:
   ```powershell
   composer install
   copy .env.example .env
   php artisan key:generate
   ```
2. Create the database user and the two databases once. Open **psql** as the `postgres` user and
   paste (choose your own password):
   ```sql
   CREATE ROLE your_db_user LOGIN PASSWORD 'choose-a-password';
   CREATE DATABASE atomic_locks      OWNER your_db_user;
   CREATE DATABASE atomic_locks_test OWNER your_db_user;
   ```
3. Open `.env` in a text editor and set:
   ```
   DB_USERNAME=your_db_user
   DB_PASSWORD=choose-a-password
   ```
4. Create the tables and check everything works:
   ```powershell
   php artisan migrate
   php artisan test
   ```
5. Continue with Part 1, Step 2.
