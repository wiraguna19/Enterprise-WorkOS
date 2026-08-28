<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controller\DepartmentController;
use App\Modules\Organization\Http\Controller\PersonController;
use App\Modules\Organization\Http\Controller\TeamController;
use Illuminate\Support\Facades\Route;

/**
 * Endpoints are named after the domain, not after the screens that consume
 * them (docs/05 §1). `permission:` is the coarse gate; per-record checks live
 * in policies inside the controllers.
 */
Route::get('departments', [DepartmentController::class, 'index'])
    ->middleware('permission:department.view');
Route::post('departments', [DepartmentController::class, 'store'])
    ->middleware(['permission:department.create', 'throttle:writes']);
Route::patch('departments/{department}', [DepartmentController::class, 'update'])
    ->middleware(['permission:department.update', 'throttle:writes']);
Route::post('departments/{department}/move', [DepartmentController::class, 'move'])
    ->middleware(['permission:department.update', 'throttle:writes']);

Route::get('teams', [TeamController::class, 'index'])
    ->middleware('permission:team.view');
Route::get('teams/{team}', [TeamController::class, 'show'])
    ->middleware('permission:team.view');
Route::post('teams', [TeamController::class, 'store'])
    ->middleware(['permission:team.create', 'throttle:writes']);
Route::post('teams/{team}/members', [TeamController::class, 'addMember'])
    ->middleware(['permission:team.manage_members', 'throttle:writes']);
Route::delete('teams/{team}/members/{membership}', [TeamController::class, 'removeMember'])
    ->middleware(['permission:team.manage_members', 'throttle:writes']);

Route::get('people', [PersonController::class, 'index'])
    ->middleware('permission:person.view');
Route::get('people/{membership}', [PersonController::class, 'show'])
    ->middleware('permission:person.view');
