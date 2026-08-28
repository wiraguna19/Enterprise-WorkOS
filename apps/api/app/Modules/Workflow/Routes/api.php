<?php

declare(strict_types=1);

use App\Modules\Workflow\Http\Controller\RecurrenceController;
use App\Modules\Workflow\Http\Controller\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('workflows', [WorkflowController::class, 'index'])
    ->middleware('permission:workflow.view');

// Drives the status picker. One query serves both the UI and the API check.
Route::get('work-items/{reference}/available-transitions', [WorkflowController::class, 'availableTransitions'])
    ->middleware('permission:work_item.view');

// ── Recurrence ──────────────────────────────────────────────────────────────
// Creating a standing instruction to create work needs the permission to create
// work — no more, and not less either (docs/06 §2).
Route::get('recurrences', [RecurrenceController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('recurrences', [RecurrenceController::class, 'store'])
    ->middleware(['permission:work_item.create', 'throttle:writes']);
Route::delete('recurrences/{id}', [RecurrenceController::class, 'destroy'])
    ->middleware(['permission:work_item.create', 'throttle:writes']);

Route::get('workflow-rules', [WorkflowController::class, 'rules'])
    ->middleware('permission:workflow.view');
Route::get('workflow-rules/{id}/runs', [WorkflowController::class, 'ruleRuns'])
    ->middleware('permission:workflow.manage');
