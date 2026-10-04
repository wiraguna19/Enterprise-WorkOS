<?php

declare(strict_types=1);

use App\Modules\Search\Http\Controller\RecentSearchController;
use App\Modules\Search\Http\Controller\SearchController;
use Illuminate\Support\Facades\Route;

/**
 * Search is throttled harder than the rest of the API (docs/05 §7): the command
 * palette fires on keystrokes, and every one of them is three ranked queries.
 */
Route::get('search', SearchController::class)
    ->middleware(['permission:work_item.view', 'throttle:search'])
    ->name('search');

// The palette's memory of this person's own searches. Behind the same
// permission as searching: somebody who cannot search has nothing to recall.
Route::get('me/recent-searches', [RecentSearchController::class, 'index'])
    ->middleware('permission:work_item.view')
    ->name('search.recent.index');

Route::post('me/recent-searches', [RecentSearchController::class, 'store'])
    ->middleware(['permission:work_item.view', 'throttle:writes'])
    ->name('search.recent.store');

Route::delete('me/recent-searches', [RecentSearchController::class, 'destroyAll'])
    ->middleware('permission:work_item.view')
    ->name('search.recent.destroy_all');

Route::delete('me/recent-searches/{id}', [RecentSearchController::class, 'destroy'])
    ->middleware('permission:work_item.view')
    ->whereUuid('id')
    ->name('search.recent.destroy');
