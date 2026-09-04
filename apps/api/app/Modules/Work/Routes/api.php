<?php

declare(strict_types=1);

use App\Modules\Work\Http\Controller\ActivityController;
use App\Modules\Work\Http\Controller\AssignmentController;
use App\Modules\Work\Http\Controller\MyTimeController;
use App\Modules\Work\Http\Controller\MyWorkController;
use App\Modules\Work\Http\Controller\ProjectController;
use App\Modules\Work\Http\Controller\TimeEntryController;
use App\Modules\Work\Http\Controller\WorkItemController;
use Illuminate\Support\Facades\Route;

/**
 * Endpoints mirror the domain, not the screens (docs/05 §1).
 *
 * Note what is NOT here: no /board-data, no /dashboard-cards. The Next.js BFF
 * composes UI-shaped responses so the API can stay domain-shaped.
 */

// ── My Work ─────────────────────────────────────────────────────────────────
// "My work" is real vocabulary in this system, so it earns endpoints.
Route::get('me/work', [MyWorkController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::get('me/work/counts', [MyWorkController::class, 'counts'])
    ->middleware('permission:work_item.view');
Route::get('me/work/needs-attention', [MyWorkController::class, 'needsAttention'])
    ->middleware('permission:work_item.view');

// Gated on seeing work, not on logging it: someone whose ability to log time
// was revoked still owns the hours they already logged, and a timesheet that
// empties out when a permission changes destroys the record it is for.
Route::get('me/time', [MyTimeController::class, 'index'])
    ->middleware('permission:work_item.view');

// ── Projects ────────────────────────────────────────────────────────────────
Route::get('projects', [ProjectController::class, 'index'])
    ->middleware('permission:project.view');
Route::post('projects', [ProjectController::class, 'store'])
    ->middleware(['permission:project.create', 'throttle:writes']);
Route::get('projects/{key}', [ProjectController::class, 'show'])
    ->middleware('permission:project.view')->name('projects.show');
Route::get('projects/{key}/board', [ProjectController::class, 'board'])
    ->middleware('permission:project.view');

// ── Work items ──────────────────────────────────────────────────────────────
// Keyed by human reference (ENG-142) rather than UUID: it is what people paste
// into chat, and a readable URL is a small thing that makes a product feel
// considered (docs/08 §2).
Route::get('work-items', [WorkItemController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items', [WorkItemController::class, 'store'])
    ->middleware(['permission:work_item.create', 'throttle:writes']);
Route::get('work-items/{reference}', [WorkItemController::class, 'show'])
    ->middleware('permission:work_item.view')->name('work-items.show');
Route::patch('work-items/{reference}', [WorkItemController::class, 'update'])
    ->middleware(['permission:work_item.update', 'throttle:writes']);
Route::delete('work-items/{reference}', [WorkItemController::class, 'destroy'])
    ->middleware(['permission:work_item.delete', 'throttle:writes']);

// State changes are ACTIONS, not field edits: they carry rules and side
// effects, and modelling them as PATCH pushes workflow logic into the client.
Route::post('work-items/{reference}/transition', [WorkItemController::class, 'transition'])
    ->middleware(['permission:work_item.transition', 'throttle:writes']);
Route::post('work-items/{reference}/move', [WorkItemController::class, 'move'])
    ->middleware(['permission:work_item.update', 'throttle:writes']);

// `work-items/{reference}/available-transitions` is NOT here. It lives in the
// Workflow module's routes, because answering it means evaluating the workflow
// graph and this module cannot do that. Registering it in both places meant
// whichever provider booted last silently won.

// ── Assignment ──────────────────────────────────────────────────────────────
Route::post('work-items/{reference}/assign', [AssignmentController::class, 'store'])
    ->middleware(['permission:work_item.assign', 'throttle:writes']);
// Accepting work assigned to YOU needs no permission — only identity.
Route::post('work-items/{reference}/accept', [AssignmentController::class, 'accept'])
    ->middleware('throttle:writes');
Route::delete('work-items/{reference}/assignees/{assignment}', [AssignmentController::class, 'destroy'])
    ->middleware(['permission:work_item.assign', 'throttle:writes']);
// The timeline. Behind `activity.view`, which until now was a permission with
// nothing behind it — granted to every role since Phase 1 and unreachable.
Route::get('work-items/{reference}/activity', [ActivityController::class, 'index'])
    ->middleware('permission:activity.view');

Route::get('work-items/{reference}/assignments', [AssignmentController::class, 'history'])
    ->middleware('permission:work_item.view');

// ── Time ────────────────────────────────────────────────────────────────────
// Reading time needs only the right to see the work; logging it is its own
// permission, because "may see this item" and "may put hours against it" are
// different questions (docs/06 §2).
Route::get('work-items/{reference}/time-entries', [TimeEntryController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items/{reference}/time-entries', [TimeEntryController::class, 'store'])
    ->middleware(['permission:work_item.log_time', 'throttle:writes']);
Route::delete('work-items/{reference}/time-entries/{entry}', [TimeEntryController::class, 'destroy'])
    ->middleware(['permission:work_item.log_time', 'throttle:writes']);
