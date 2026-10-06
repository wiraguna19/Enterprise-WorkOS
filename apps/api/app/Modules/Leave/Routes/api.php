<?php

declare(strict_types=1);

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
