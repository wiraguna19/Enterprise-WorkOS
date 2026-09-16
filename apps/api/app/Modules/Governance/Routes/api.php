<?php

declare(strict_types=1);

use App\Modules\Governance\Http\Controller\AuditLogController;
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
