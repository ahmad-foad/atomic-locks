# Swagger in the Atomic Locks project: step by step

Swagger UI is a web page that lists every API route of this project and lets you **send real
requests to the running app** from the browser. Every route has its documentation (path parameter,
body, possible responses) written as PHP attributes next to the code. No Postman file is needed.

---

## Part 0: The ideas behind it

### What are OpenAPI and Swagger?

- **OpenAPI** is a standard file format (JSON or YAML) that describes a REST API: which URLs exist,
  which HTTP method each one uses, what you must send (path values, headers, body) and every answer
  the server can give back. It is like a contract between the server and anyone who calls it.
- **Swagger UI** is a free web page that reads an OpenAPI file and draws it as an interactive manual.
  Every route becomes a coloured row you can open, fill in and **execute** against the real server.
- **L5-Swagger** (`darkaonline/l5-swagger`) is the Laravel package that joins the two: it reads the
  `#[OA\...]` attributes in our PHP code, writes the OpenAPI file, and serves Swagger UI.

### Why we use it instead of Postman

| Postman collection (before) | Swagger / OpenAPI (now) |
|---|---|
| A separate JSON file that has to be imported | Opens in any browser at `/api/documentation` |
| Easily gets out of date when the code changes | Built from attributes **next to the code**, rebuilt on every page load |
| Shows only the requests someone saved | Shows every route, every parameter, every possible response with an example |
| Only for people who have Postman | Standard format: can be imported into Postman, Insomnia or code generators |

### Why this matters for this project

The project has **three buy routes with the same business logic**: one with no lock, one protected by
`Cache::lock`, and one protected by a PostgreSQL row lock (`lockForUpdate`). In Swagger they sit
next to each other with the **same body and the same responses**, which makes the point of the project
easy to see: the only difference is the lock. Swagger also documents the special answers, such as
`409 Sold out` and `503` + `Retry-After` when a buyer waits too long for the lock.

### How to read one Swagger row

| Part of the row | What it means |
|---|---|
| Coloured label (`POST`, `GET`) | The HTTP method. `POST` changes data (buys a ticket), `GET` only reads |
| Path (`/api/events/{event}/buy`) | The URL. `{event}` is a placeholder you fill in with an event number |
| Summary text | One line saying what the route does (`❌ BEFORE`, `✅ AFTER`, `✅ ALTERNATIVE`) |
| **Parameters** | Values you send in the URL. Here: `event` (a whole number). A red `* required` means Swagger will not send without it |
| **Request body** | The JSON you send. Swagger fills in an example you can edit |
| **Responses** | Every status code the route can return, with a description and example body |
| **Schemas** (bottom of the page) | The shape of the JSON objects, field by field (type, required, example) |

After **Execute**, Swagger shows the **curl** command it sent, the **Request URL**, the **response
code**, the **response body** and the **response headers**.

### HTTP status codes used in this project

| Code | Name | Meaning here |
|---|---|---|
| `200` | OK | Event details were read, or the demo was reset |
| `201` | Created | A ticket was bought (a new order was created) |
| `404` | Not Found | No event with that id (it does not exist, was deleted by a reset, or the id is not a number) |
| `409` | Conflict | **Sold out**: no tickets left |
| `422` | Unprocessable Content | Validation failed: `buyer` missing or too long, or a bad `tickets` value on reset |
| `503` | Service Unavailable | `Cache::lock` route only: the buyer waited more than 5 seconds for the lock. Header `Retry-After: 1` tells the client to try again in 1 second |

---

## Part 1: How it works

```
PHP attributes in the code
        │   (#[OA\Post(...)], #[OA\Schema(...)], #[OA\Response(...)], ...)
        ▼
l5-swagger scans the app/ folder
        │   php artisan l5-swagger:generate
        │   (automatic on every page load, because L5_SWAGGER_GENERATE_ALWAYS=true in .env)
        ▼
storage/api-docs/api-docs.json        ← the OpenAPI 3 specification
        │   served at http://127.0.0.1:8000/docs
        ▼
Swagger UI page                        ← http://127.0.0.1:8000/api/documentation
        │   "Try it out" → "Execute"
        ▼
Real HTTP request to the Laravel app → real response from PostgreSQL
```

In words: we never write the documentation file by hand. We write attributes on the controller
methods; the package turns them into `api-docs.json`; Swagger UI reads that file and draws the page.
When we change the code and its attribute, the page changes too.

| Piece | Where | What it does |
|---|---|---|
| `darkaonline/l5-swagger` | `composer.json` | Laravel package that contains swagger-php (reads the attributes) and Swagger UI (the page) |
| `config/l5-swagger.php` | config | Docs URL (`api/documentation`), folder to scan (`app/`), output file |
| `app/Http/Controllers/Controller.php` | attributes on the class | API title and description (`OA\Info`), the groups `Tickets` and `Demo` (`OA\Tag`), and the data shapes `BuyRequest`, `TicketPurchased`, `Message` (`OA\Schema`) |
| `app/OpenApi/SharedComponents.php` | attributes on an empty class | Pieces reused by several routes: the `{event}` path parameter and the responses `Purchased` (201), `SoldOut` (409), `NotFound` (404), `ValidationError` (422) |
| `app/Http/Controllers/TicketController.php` | attributes on each method | One operation per route: URL, summary, description, body and responses |

The three buy routes share the same parameter, body and responses through references (`ref:`), so
each is written once. Only the `Cache::lock` route adds `503` + `Retry-After`. Example (shortened):

```php
#[OA\Post(
    path: '/api/events/{event}/buy',
    summary: '✅ AFTER: buy a ticket protected by Cache::lock',
    tags: ['Tickets'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/EventId')],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/BuyRequest')),
    responses: [
        new OA\Response(response: 201, ref: '#/components/responses/Purchased'),
        new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        new OA\Response(response: 409, ref: '#/components/responses/SoldOut'),
        new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        new OA\Response(response: 503, description: 'Waited too long for the lock',
            headers: [new OA\Header(header: 'Retry-After', ...)], ...),
    ],
)]
public function buySafe(Request $request, Event $event): JsonResponse
```

How to read it: `path` and `OA\Post` give the method and URL; `tags` puts it in the **Tickets** group;
`ref: '#/components/...'` means "use the shared piece with this name" instead of writing it again.
That is why a change to, for example, the `SoldOut` example appears on all three buy routes at once.

**Why the shared pieces live in their own class:** swagger-php collects components from attributes.
When the shared parameter and responses were placed on the same class as the schemas, the schemas
disappeared from the output, so they were moved to `app/OpenApi/SharedComponents.php`.

**Input rules that Swagger shows:** `event` must be a whole number (the route only matches digits,
so `abc` gives `404` instead of a database error), `buyer` is required and at most 100 characters,
and `tickets` on reset must be a whole number from 0 to 1000.

---

## Part 2: Open Swagger UI

1. Open PowerShell **in this project's folder** and start the servers:
   ```powershell
   cd "C:\Users\home\Downloads\atomic-locks"
   php demo/serve.php
   ```
   Keep this window open. (Make sure the Idempotency Keys servers are stopped first, because both
   projects use port 8000.)
2. Open the browser at **http://127.0.0.1:8000/api/documentation**
3. You see the title **Atomic Locks Demo API**, a short description, and two groups:
   - **Tickets**:
      - `POST /api/unsafe/events/{event}/buy`: ❌ no lock
      - `POST /api/events/{event}/buy`: ✅ `Cache::lock`
      - `POST /api/row-lock/events/{event}/buy`: ✅ PostgreSQL row lock (`lockForUpdate`)
      - `GET /api/events/{event}`: stock and buyers
   - **Demo**: `POST /api/demo/reset`
   - **Schemas** at the bottom: the data shapes used by the routes.

Click a group name to open or close it. Click a row to open that route.

---

## Part 3: Try every route (step by step)

Every route works the same way: **click the row → "Try it out" → fill in → "Execute"**. Below the
button Swagger shows the `curl` command it sent, the **Request URL**, the **response code**, the
**response body** and the **response headers**.

### Step 1: Create an event with 1 ticket
1. Open **Demo → `POST /api/demo/reset`** → **Try it out**.
2. The body is already `{ "tickets": 1 }`. Press **Execute**.
3. Result: `200` and something like `{"event_id": 15, "tickets_left": 1}`.
   **Remember the `event_id`**: every other route needs it. (The number grows every time you reset,
   and a reset deletes the old events, so an old number then gives `404`.)

**Why:** one ticket and many buyers is the situation where overselling is easiest to see.

### Step 2: Buy the last ticket
1. Open **`POST /api/events/{event}/buy`** (the `Cache::lock` route) → **Try it out**.
2. The **event** field is empty. Type your `event_id` (for example `15`).
3. The body is `{ "buyer": "Ali" }`. Press **Execute**.
4. Result: `201`:
   ```json
   { "message": "Ticket purchased!", "order_id": 95, "tickets_left": 0 }
   ```

**What happens inside:** the route takes the lock `event:15:tickets`, re-reads the stock from the
database, checks it, simulates a 300 ms card payment, creates the order and lowers the stock. Then
the lock is released automatically.

### Step 3: The next buyer gets "Sold out"
1. Change the body to `{ "buyer": "Sara" }` and press **Execute**.
2. Result: `409` and `{"message": "Sold out."}`. The lock let Sara in, she re-read the real stock (0)
   and got a clean answer.

**Why `409`:** "Conflict" means the request is valid but clashes with the current state of the data
(there is nothing left to sell). It is not a server error.

### Step 4: See who bought it
Open **`GET /api/events/{event}`** → **Try it out** → your `event_id` → **Execute**:

```json
{ "event": "Rock Concert - Front Row", "tickets_left": 0, "tickets_sold": 1, "buyers": ["Ali"] }
```

`tickets_sold` is counted from the orders table, so it shows what really happened, even when the
stock number is wrong (as in the unsafe demo).

### Step 5: Try the other two routes the same way
Reset (Step 1) to get a new `event_id`, then repeat Steps 2–4 with:
- **`POST /api/row-lock/events/{event}/buy`**: same answers (`201`, then `409`), protected by
  `SELECT … FOR UPDATE` inside a PostgreSQL transaction. Other buyers wait inside PostgreSQL until the
  first transaction commits.
- **`POST /api/unsafe/events/{event}/buy`**: also `201` then `409` **when you click one at a time**.
  This is the point of the project: the bug only appears when many buyers arrive **at the same
  moment**, which a human cannot click. That is what `php demo/demo.php` is for (Part 4).

### Step 6: Error responses
| What you send | Result |
|---|---|
| Body `{}` (no buyer) | `422`: `"The buyer field is required."` |
| `event` = `99999` (does not exist) | `404`: `"No query results for model [App\\Models\\Event] 99999"` |
| `event` = `abc` | Swagger refuses to send it (the field must be a number). From PowerShell or curl the API answers `404` |
| Reset body `{ "tickets": -5 }` | `422`: `"The tickets field must be at least 0."` (allowed: 0 to 1000) |

**Why these checks matter:** bad input is stopped with a clear `4xx` answer before it reaches the
database, so the client always learns what it did wrong and the server never crashes with a `500`.

### Responses you can read in Swagger but not click
| Code | Why | How to see it |
|---|---|---|
| `503` + `Retry-After: 1` (Cache::lock only) | Happens only when a buyer waits more than 5 seconds in the lock queue | Run the 10-ticket demo with 20 servers: in our real run 1 buyer got `503` |
| Overselling (stock below 0) on the unsafe route | Needs many requests at the same moment | Run `php demo/demo.php`: 50 buyers at once |

Swagger still shows `503` and its `Retry-After` header under **Responses** for the `Cache::lock`
route, with an example body. That is what makes it documentation, not only a test tool.

---

## Part 4: Swagger + the live demo (for the presentation, about 3 minutes)

1. Open `http://127.0.0.1:8000/api/documentation` and show the three buy routes side by side:
   same body, same responses, only the lock is different.
2. Do Steps 1–3 once on the `Cache::lock` route: `201`, then `409 Sold out`.
3. Then, in a second PowerShell window in the same folder, run the real race:
   ```powershell
   cd "C:\Users\home\Downloads\atomic-locks"
   php demo/demo.php
   ```
   Real result: no lock **10 sold (stock −9)**, `Cache::lock` **1 sold**, `lockForUpdate` **1 sold**.
4. Back in Swagger, open `GET /api/events/{event}` with the event number from the demo's
   `❌ BEFORE (no lock)  POST /api/unsafe/events/12/buy` line (here `12`), and show the 10 names
   in `buyers` for a single ticket.

## Useful commands

```powershell
php artisan l5-swagger:generate     # rebuild storage/api-docs/api-docs.json by hand
php artisan route:list --path=doc   # shows the Swagger routes: /api/documentation and /docs
```

The raw OpenAPI file can be opened at **http://127.0.0.1:8000/docs** and imported into any OpenAPI
tool (Postman, Insomnia, client generators).

## Glossary

| Word | Meaning |
|---|---|
| Race condition | The result depends on the timing of parallel requests (here: two buyers both see "1 left") |
| Check-then-act | Reading a value, then changing it in a separate step. Unsafe without a lock |
| Atomic lock | A lock that only one request can take at a time, stored where every server can see it |
| `Cache::lock` | Laravel's atomic lock. Here it is a row in the `cache_locks` table; with Redis it works across servers |
| `lockForUpdate` | PostgreSQL `SELECT … FOR UPDATE`: locks the event row until the transaction ends |
| Endpoint / route | One URL + method of the API, for example `POST /api/events/{event}/buy` |
| Path parameter | A value inside the URL, like `{event}` |
| Schema | The description of a JSON object: its fields, their types and which are required |
| `Retry-After` | A response header that tells the client how many seconds to wait before trying again |
