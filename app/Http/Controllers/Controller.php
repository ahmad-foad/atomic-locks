<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Atomic Locks Demo API',
    description: "Stops overselling the last ticket.\n\n"
        .'Three ways to buy a ticket, with the same business logic: '
        .'`/api/unsafe/...` has **no lock** (race condition), `/api/events/...` uses **Cache::lock** (atomic lock), '
        .'`/api/row-lock/...` uses a PostgreSQL **row lock** (`DB::transaction` + `lockForUpdate`).'
        ."\n\nStart with `POST /api/demo/reset` to create an event and get its `event_id`.",
)]
#[OA\Tag(name: 'Tickets', description: 'Buying tickets with and without a lock')]
#[OA\Tag(name: 'Demo', description: 'Helpers for the live demo')]
#[OA\Schema(
    schema: 'BuyRequest',
    required: ['buyer'],
    properties: [new OA\Property(property: 'buyer', type: 'string', maxLength: 100, example: 'Ali')],
)]
#[OA\Schema(
    schema: 'TicketPurchased',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Ticket purchased!'),
        new OA\Property(property: 'order_id', type: 'integer', example: 1),
        new OA\Property(property: 'tickets_left', type: 'integer', example: 0),
    ],
)]
#[OA\Schema(
    schema: 'Message',
    properties: [new OA\Property(property: 'message', type: 'string')],
)]
abstract class Controller
{
    //
}
