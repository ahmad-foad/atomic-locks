<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TicketLockTest extends TestCase
{
    use RefreshDatabase;

    private function concert(int $tickets = 1): Event
    {
        return Event::create(['name' => 'Rock Concert', 'tickets_left' => $tickets, 'price' => 15000]);
    }

    public function test_a_buyer_can_buy_the_last_ticket(): void
    {
        $event = $this->concert(1);

        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'Ali'])
            ->assertCreated()
            ->assertJsonPath('tickets_left', 0);

        $this->assertSame(1, Order::count());
    }

    public function test_second_buyer_gets_sold_out(): void
    {
        $event = $this->concert(1);

        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'Ali'])->assertCreated();
        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'Sara'])->assertStatus(409);

        $this->assertSame(0, $event->fresh()->tickets_left);
        $this->assertSame(1, Order::count());
    }

    public function test_unsafe_route_oversells_when_it_acts_on_a_stale_read(): void
    {
        // Reproduce the race deterministically: buyer B read "1 ticket left"
        // BEFORE buyer A finished, so B passes the check with stale data.
        $event = $this->concert(1);
        $staleCopy = Event::find($event->id);       // B's read: tickets_left = 1

        $this->postJson("/api/unsafe/events/{$event->id}/buy", ['buyer' => 'A'])->assertCreated();

        $controller = app(\App\Http\Controllers\TicketController::class);
        $response = $controller->buyUnsafe(
            \Illuminate\Http\Request::create('/', 'POST', ['buyer' => 'B']),
            $staleCopy,
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(-1, $event->fresh()->tickets_left); // OVERSOLD
        $this->assertSame(2, Order::count());
    }

    public function test_safe_route_rereads_stock_inside_the_lock(): void
    {
        $event = $this->concert(1);
        $staleCopy = Event::find($event->id);       // stale read: tickets_left = 1

        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'A'])->assertCreated();

        $controller = app(\App\Http\Controllers\TicketController::class);
        $response = $controller->buySafe(
            \Illuminate\Http\Request::create('/', 'POST', ['buyer' => 'B']),
            $staleCopy,
        );

        $this->assertSame(409, $response->getStatusCode()); // refresh() inside the lock saw 0
        $this->assertSame(0, $event->fresh()->tickets_left);
        $this->assertSame(1, Order::count());
    }

    public function test_row_lock_route_reads_stock_with_select_for_update(): void
    {
        $event = $this->concert(1);
        $staleCopy = Event::find($event->id);       // stale read: tickets_left = 1

        $this->postJson("/api/row-lock/events/{$event->id}/buy", ['buyer' => 'A'])
            ->assertCreated()
            ->assertJsonPath('tickets_left', 0);

        // Record the SQL of the second purchase, made with the stale model.
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $controller = app(\App\Http\Controllers\TicketController::class);
        $response = $controller->buyRowLock(
            \Illuminate\Http\Request::create('/', 'POST', ['buyer' => 'B']),
            $staleCopy,
        );

        $this->assertSame(409, $response->getStatusCode()); // the locked re-read saw 0
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'for update')));
        $this->assertSame(0, $event->fresh()->tickets_left);
        $this->assertSame(1, Order::count());
    }

    public function test_buyer_gets_503_when_the_lock_is_busy_too_long(): void
    {
        $event = $this->concert(5);

        // Another request is holding the lock (e.g. a slow payment).
        $held = Cache::lock("event:{$event->id}:tickets", 10);
        $this->assertTrue($held->get());

        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'Ali'])
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1');

        $this->assertSame(0, Order::count());

        // Once the lock is released, buying works again.
        $held->release();
        $this->postJson("/api/events/{$event->id}/buy", ['buyer' => 'Ali'])->assertCreated();
    }

    public function test_lock_is_released_after_each_purchase(): void
    {
        $event = $this->concert(3);

        foreach (['Ali', 'Sara', 'Omar'] as $buyer) {
            $this->postJson("/api/events/{$event->id}/buy", ['buyer' => $buyer])->assertCreated();
        }

        $this->assertSame(0, $event->fresh()->tickets_left);
        $this->assertTrue(Cache::lock("event:{$event->id}:tickets", 10)->get()); // nobody holds it
    }

    public function test_non_numeric_event_id_returns_404_not_a_database_error(): void
    {
        $this->getJson('/api/events/abc')->assertNotFound();
        $this->postJson('/api/events/abc/buy', ['buyer' => 'Ali'])->assertNotFound();
        $this->postJson('/api/row-lock/events/abc/buy', ['buyer' => 'Ali'])->assertNotFound();
        $this->postJson('/api/unsafe/events/abc/buy', ['buyer' => 'Ali'])->assertNotFound();
    }

    public function test_reset_validates_the_ticket_count(): void
    {
        $this->postJson('/api/demo/reset', ['tickets' => -5])->assertUnprocessable()->assertJsonValidationErrors('tickets');
        $this->postJson('/api/demo/reset', ['tickets' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('tickets');
        $this->postJson('/api/demo/reset', ['tickets' => 99999999999])->assertUnprocessable()->assertJsonValidationErrors('tickets');

        $this->postJson('/api/demo/reset')->assertOk()->assertJson(['tickets_left' => 1]);
        $this->postJson('/api/demo/reset', ['tickets' => 10])->assertOk()->assertJson(['tickets_left' => 10]);
    }
}
