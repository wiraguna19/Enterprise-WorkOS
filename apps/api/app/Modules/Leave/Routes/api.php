<?php

declare(strict_types=1);

use App\Modules\Leave\Http\Controller\LeaveRequestController;
use App\Modules\Leave\Http\Controller\LeaveSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Leave (ADR 0063). The organization's rules are `leave.manage`'s; the
 * holidays are readable by anyone who may ask for leave, because a request
 * form that does not know the office is closed on Friday counts Friday.
 */
Route::get('leave/settings', [LeaveSettingsController::class, 'show'])
    ->middleware('permission:leave.manage')
    ->name('leave.settings.show');

Route::post('leave/settings/preset', [LeaveSettingsController::class, 'applyPreset'])
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.settings.preset');

Route::put('leave/settings/policy', [LeaveSettingsController::class, 'updatePolicy'])
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.settings.policy');

Route::post('leave/types', [LeaveSettingsController::class, 'storeType'])
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.types.store');

Route::patch('leave/types/{id}', [LeaveSettingsController::class, 'updateType'])
    ->whereUuid('id')
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.types.update');

Route::get('leave/holidays', [LeaveSettingsController::class, 'holidays'])
    ->middleware('permission:leave.request')
    ->name('leave.holidays.index');

Route::post('leave/holidays', [LeaveSettingsController::class, 'storeHoliday'])
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.holidays.store');

Route::delete('leave/holidays/{id}', [LeaveSettingsController::class, 'destroyHoliday'])
    ->whereUuid('id')
    ->middleware(['permission:leave.manage', 'throttle:writes'])
    ->name('leave.holidays.destroy');

/*
 * Requests (slice 2). `leave.request` to ask and to decide: deciding is a
 * manager's or HR's, and the service checks which — a reporting line is not
 * a permission.
 */
Route::get('leave/me', [LeaveRequestController::class, 'mine'])
    ->middleware('permission:leave.request')
    ->name('leave.me');

Route::get('leave/quote', [LeaveRequestController::class, 'quote'])
    ->middleware('permission:leave.request')
    ->name('leave.quote');

Route::post('leave/requests', [LeaveRequestController::class, 'store'])
    ->middleware(['permission:leave.request', 'throttle:writes'])
    ->name('leave.requests.store');

Route::get('leave/awaiting', [LeaveRequestController::class, 'awaiting'])
    ->middleware('permission:leave.request')
    ->name('leave.awaiting');

Route::post('leave/requests/{id}/approve', [LeaveRequestController::class, 'approve'])
    ->whereUuid('id')
    ->middleware(['permission:leave.request', 'throttle:writes'])
    ->name('leave.requests.approve');

Route::post('leave/requests/{id}/reject', [LeaveRequestController::class, 'reject'])
    ->whereUuid('id')
    ->middleware(['permission:leave.request', 'throttle:writes'])
    ->name('leave.requests.reject');

Route::post('leave/requests/{id}/cancel', [LeaveRequestController::class, 'cancel'])
    ->whereUuid('id')
    ->middleware(['permission:leave.request', 'throttle:writes'])
    ->name('leave.requests.cancel');

Route::get('leave/requests', [LeaveRequestController::class, 'index'])
    ->middleware('permission:leave.manage')
    ->name('leave.requests.index');
