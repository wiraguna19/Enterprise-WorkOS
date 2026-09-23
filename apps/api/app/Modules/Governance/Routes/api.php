<?php

declare(strict_types=1);

use App\Modules\Governance\Http\Controller\AuditLogController;
use App\Modules\Governance\Http\Controller\CustomFieldController;
use Illuminate\Support\Facades\Route;

/**
 * The security audit log (ADR 0019).
 *
 * `audit_log.view` was granted to org admins in Phase 1 and consulted by
 * nothing until this route existed — one of the ten
 * `EveryPermissionMeansSomethingTest` found. One endpoint, read-only: an audit
 * log a product can write to twice is not an audit log.
 */
Route::get('audit-logs', [AuditLogController::class, 'index'])
    ->middleware('permission:audit_log.view');

/**
 * The fields an organization declared for itself (ADR 0038).
 *
 * `custom_field.manage` guards every route here, including the reads: the list
 * an ADMINISTRATOR sees contains retired fields and the answer counts behind a
 * deletion, which is organization configuration, not record content. The list a
 * FORM needs travels with the record it is for — a work item's payload carries
 * its own fields — so no screen has to hold two ideas about what a field is.
 *
 * `vocabulary` is the one exception in spirit and not in fact: it is a constant
 * list, but it is only ever read by the screen that declares a field, and
 * guarding it costs nothing.
 */
Route::prefix('custom-fields')
    ->middleware('permission:custom_field.manage')
    ->group(function (): void {
        Route::get('vocabulary', [CustomFieldController::class, 'vocabulary']);

        Route::get('{scope}', [CustomFieldController::class, 'index']);
        Route::post('{scope}', [CustomFieldController::class, 'store']);
        Route::post('{scope}/order', [CustomFieldController::class, 'reorder']);

        Route::patch('{scope}/{id}', [CustomFieldController::class, 'update']);
        // Retiring is not deleting, so it is not a DELETE. The verb has to say
        // which one happened, or a control named for the wrong one eventually
        // performs it.
        Route::post('{scope}/{id}/live', [CustomFieldController::class, 'setLive']);
        Route::delete('{scope}/{id}', [CustomFieldController::class, 'destroy']);
    });
