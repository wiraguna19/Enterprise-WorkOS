<?php

declare(strict_types=1);

use App\Modules\Calendar\Http\Controller\CalendarController;
use App\Modules\Calendar\Http\Controller\CalendarFeedController;
use Illuminate\Support\Facades\Route;

/**
 * The calendar reads what other modules own, so it asks for the permission to
 * see work rather than one of its own (docs/06 §2).
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('calendar', CalendarController::class)
        ->middleware('permission:work_item.view');

    Route::get('calendar/feed', [CalendarFeedController::class, 'show'])
        ->middleware('permission:work_item.view');
    Route::post('calendar/feed', [CalendarFeedController::class, 'store'])
        ->middleware(['permission:work_item.view', 'throttle:writes']);
    Route::delete('calendar/feed', [CalendarFeedController::class, 'destroy'])
        ->middleware(['permission:work_item.view', 'throttle:writes']);
});

/**
 * The feed itself is deliberately outside the auth group: a calendar client
 * cannot present a bearer token, so the URL is the credential. It is throttled
 * on its own — a subscribed client polls, and a leaked URL should be expensive
 * to mine.
 */
Route::get('calendar/{token}.ics', [CalendarFeedController::class, 'feed'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:calendar-feed')
    ->name('calendar.feed');
