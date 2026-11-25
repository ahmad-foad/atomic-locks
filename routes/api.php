<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// {event} must be a whole number (fits PostgreSQL bigint), otherwise 404 instead of an SQL error.
Route::pattern('event', '[0-9]{1,18}');

// ❌ BEFORE: no lock - the last ticket can be sold many times.
Route::post('/unsafe/events/{event}/buy', [TicketController::class, 'buyUnsafe']);

// ✅ AFTER: protected by Cache::lock() - exactly one buyer per ticket.
Route::post('/events/{event}/buy', [TicketController::class, 'buySafe']);

// ✅ ALTERNATIVE: PostgreSQL row lock (DB::transaction + lockForUpdate).
Route::post('/row-lock/events/{event}/buy', [TicketController::class, 'buyRowLock']);

Route::get('/events/{event}', [TicketController::class, 'show']);
Route::post('/demo/reset', [TicketController::class, 'reset']);
