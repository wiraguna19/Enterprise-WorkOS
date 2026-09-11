<?php

declare(strict_types=1);

use App\Modules\Workflow\Http\Controller\RecurrenceController;
use App\Modules\Workflow\Http\Controller\WorkflowController;
use App\Modules\Workflow\Http\Controller\WorkflowGraphController;
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

// What a rule may legally say. Read by the builder so the interface cannot
// offer a trigger, operator or action the engine does not implement — the same
// permission as reading the rules, because it describes nothing but this
// build's own vocabulary.
Route::get('workflow-vocabulary', [WorkflowController::class, 'vocabulary'])
    ->middleware('permission:workflow.view');

// Writing a rule is `workflow.manage` at the route AND WorkflowRulePolicy at
// the record. Two layers saying the same thing is deliberate (docs/06 §2); the
// day a rule becomes scoped to a project, only the policy changes.
Route::post('workflow-rules', [WorkflowController::class, 'storeRule'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);
Route::patch('workflow-rules/{id}', [WorkflowController::class, 'updateRule'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);

// ── Editing the graph (ADR 0015) ────────────────────────────────────────────
// In place, never copy-on-write: `version` and `superseded_by_id` stay unused
// until somebody needs the migration that makes them mean anything. The edits
// that would strand work or rewrite what a report already counted are refused
// by the editor, with 409 and a named reason — not hidden from the form, which
// would leave a person guessing why a control is missing.
Route::post('workflows/{id}/states', [WorkflowGraphController::class, 'storeState'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);
Route::patch('workflows/{id}/states/{stateId}', [WorkflowGraphController::class, 'updateState'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);
Route::delete('workflows/{id}/states/{stateId}', [WorkflowGraphController::class, 'destroyState'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);

Route::post('workflows/{id}/transitions', [WorkflowGraphController::class, 'storeTransition'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);
Route::delete('workflows/{id}/transitions/{transitionId}', [WorkflowGraphController::class, 'destroyTransition'])
    ->middleware(['permission:workflow.manage', 'throttle:writes']);
