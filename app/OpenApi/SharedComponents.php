<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI components (path parameter + reusable responses) for the Swagger docs.
 */
#[OA\Parameter(
    parameter: 'EventId',
    name: 'event',
    description: 'Event id (from POST /api/demo/reset)',
    in: 'path',
    required: true,
    schema: new OA\Schema(type: 'integer', minimum: 1),
)]
#[OA\Response(
    response: 'Purchased',
    description: 'Ticket bought',
    content: new OA\JsonContent(ref: '#/components/schemas/TicketPurchased'),
)]
#[OA\Response(
    response: 'SoldOut',
    description: 'No tickets left',
    content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => 'Sold out.']),
)]
#[OA\Response(
    response: 'NotFound',
    description: 'Event not found (unknown or non-numeric id)',
    content: new OA\JsonContent(ref: '#/components/schemas/Message', example: ['message' => 'No query results for model [App\Models\Event] 99999']),
)]
#[OA\Response(
    response: 'ValidationError',
    description: 'Validation error (buyer missing)',
    content: new OA\JsonContent(example: ['message' => 'The buyer field is required.', 'errors' => ['buyer' => ['The buyer field is required.']]]),
)]
final class SharedComponents
{
}
