<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class TicketController extends Controller
{
    /**
     * ❌ BEFORE: "check-then-act" with no lock.
     *
     * 50 buyers read "tickets_left = 1" at the same moment, all pass the check,
     * and all get a ticket. This is a RACE CONDITION.
     */
    #[OA\Post(
        path: '/api/unsafe/events/{event}/buy',
        operationId: 'buyTicketUnsafe',
        summary: '❌ BEFORE: buy a ticket with NO lock',
        description: 'Check-then-act with no lock. Many buyers at the same moment can all pass the check, so the last ticket is sold many times (stock goes below 0).',
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EventId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BuyRequest')),
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/Purchased'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/SoldOut'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function buyUnsafe(Request $request, Event $event): JsonResponse
    {
        $buyer = $request->validate(['buyer' => ['required', 'string', 'max:100']])['buyer'];

        return $this->sell($event, $buyer, protectedBy: null);
    }

    /**
     * ✅ AFTER: the same code, wrapped in an ATOMIC LOCK.
     *
     * Only ONE request at a time can hold the lock "event:{id}:tickets".
     * The others wait (up to 5 seconds) for their turn, then see the real stock.
     */
    #[OA\Post(
        path: '/api/events/{event}/buy',
        operationId: 'buyTicket',
        summary: '✅ AFTER: buy a ticket protected by Cache::lock',
        description: 'Only one request at a time holds the lock `event:{id}:tickets` (stored in the `cache_locks` table). Others wait up to 5 seconds, then get `503`.',
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EventId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BuyRequest')),
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/Purchased'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/SoldOut'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(
                response: 503,
                description: 'Waited too long for the lock',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 1))],
                content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => 'Too many buyers right now, please try again.']),
            ),
        ],
    )]
    public function buySafe(Request $request, Event $event): JsonResponse
    {
        $buyer = $request->validate(['buyer' => ['required', 'string', 'max:100']])['buyer'];

        $lock = Cache::lock("event:{$event->id}:tickets", seconds: 10); // auto-released after 10s if the process dies

        try {
            return $lock->block(config('tickets.lock_wait_seconds'), function () use ($event, $buyer) { // wait in line (max 5s)
                $event->refresh(); // read the stock AGAIN, now that we own the lock

                return $this->sell($event, $buyer, protectedBy: 'cache-lock');
            });                                                          // lock released automatically here
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Too many buyers right now, please try again.',
            ], 503)->header('Retry-After', '1');
        }
    }

    /**
     * ✅ ALTERNATIVE: a DATABASE ROW LOCK (SELECT ... FOR UPDATE).
     *
     * PostgreSQL locks the event row until the transaction ends. Other buyers'
     * SELECT ... FOR UPDATE waits for that row, then reads the real stock.
     * SQLite has no row locks, so this needed PostgreSQL (or MySQL).
     */
    #[OA\Post(
        path: '/api/row-lock/events/{event}/buy',
        operationId: 'buyTicketRowLock',
        summary: '✅ ALTERNATIVE: buy a ticket protected by a PostgreSQL row lock',
        description: '`DB::transaction` + `lockForUpdate()` runs `SELECT ... FOR UPDATE`: PostgreSQL locks the event row until COMMIT, so the other buyers wait and then read the real stock.',
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EventId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BuyRequest')),
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/Purchased'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/SoldOut'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
        ],
    )]
    public function buyRowLock(Request $request, Event $event): JsonResponse
    {
        $buyer = $request->validate(['buyer' => ['required', 'string', 'max:100']])['buyer'];

        return DB::transaction(function () use ($event, $buyer) {
            $locked = Event::whereKey($event->id)->lockForUpdate()->firstOrFail(); // wait for the row, read the REAL stock

            return $this->sell($locked, $buyer, protectedBy: 'row-lock');
        });                                                                        // COMMIT releases the row lock
    }

    /**
     * The business logic is identical for all routes - only the lock is different.
     */
    private function sell(Event $event, string $buyer, ?string $protectedBy): JsonResponse
    {
        // 1. CHECK
        if ($event->tickets_left < 1) {
            return response()->json(['message' => 'Sold out.'], 409);
        }

        // Simulate charging the card / talking to the payment gateway.
        // This delay is the "danger window" where other requests sneak in.
        usleep((int) config('tickets.payment_delay_ms') * 1000);

        // 2. ACT
        $order = Order::create([
            'event_id' => $event->id,
            'buyer' => $buyer,
            'amount' => $event->price,
            'protected_by' => $protectedBy,
        ]);
        $event->decrement('tickets_left');

        return response()->json([
            'message' => 'Ticket purchased!',
            'order_id' => $order->id,
            'tickets_left' => $event->tickets_left,
        ], 201);
    }

    #[OA\Get(
        path: '/api/events/{event}',
        operationId: 'showEvent',
        summary: 'Stock and buyers of an event',
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EventId')],
        responses: [
            new OA\Response(response: 200, description: 'Event stock', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'event', type: 'string', example: 'Rock Concert - Front Row'),
                new OA\Property(property: 'tickets_left', type: 'integer', example: 0),
                new OA\Property(property: 'tickets_sold', type: 'integer', example: 1),
                new OA\Property(property: 'buyers', type: 'array', items: new OA\Items(type: 'string'), example: ['Ali']),
            ])),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function show(Event $event): JsonResponse
    {
        return response()->json([
            'event' => $event->name,
            'tickets_left' => $event->tickets_left,
            'tickets_sold' => $event->orders()->count(),
            'buyers' => $event->orders()->pluck('buyer'),
        ]);
    }

    /**
     * Demo helper: start again with N tickets.
     */
    #[OA\Post(
        path: '/api/demo/reset',
        operationId: 'resetDemo',
        summary: 'Delete all orders and create a new event',
        tags: ['Demo'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'tickets', type: 'integer', default: 1, maximum: 1000, minimum: 0, example: 1),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'New event created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'event_id', type: 'integer', example: 1),
                new OA\Property(property: 'tickets_left', type: 'integer', example: 1),
            ])),
            new OA\Response(response: 422, description: 'tickets is not a whole number between 0 and 1000', content: new OA\JsonContent(example: [
                'message' => 'The tickets field must be at least 0.',
                'errors' => ['tickets' => ['The tickets field must be at least 0.']],
            ])),
        ],
    )]
    public function reset(Request $request): JsonResponse
    {
        $tickets = (int) ($request->validate([
            'tickets' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ])['tickets'] ?? 1);

        Order::query()->delete();
        Event::query()->delete();

        $event = Event::create([
            'name' => 'Rock Concert - Front Row',
            'tickets_left' => $tickets,
            'price' => 15000,
        ]);

        return response()->json(['event_id' => $event->id, 'tickets_left' => $tickets]);
    }
}
