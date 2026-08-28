<?php

declare(strict_types=1);

use App\Modules\Search\Http\Controller\SearchController;
use Illuminate\Support\Facades\Route;

/**
 * Search is throttled harder than the rest of the API (docs/05 §7): the command
 * palette fires on keystrokes, and every one of them is three ranked queries.
 */
Route::get('search', SearchController::class)
    ->middleware(['permission:work_item.view', 'throttle:search'])
    ->name('search');
