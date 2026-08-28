<?php

declare(strict_types=1);

use App\Modules\Approval\Http\Controller\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('approvals', [ApprovalController::class, 'index'])
    ->middleware('permission:approval.decide');
Route::get('approvals/{id}', [ApprovalController::class, 'show'])
    ->middleware('permission:approval.decide');
Route::post('approvals/{id}/decide', [ApprovalController::class, 'decide'])
    ->middleware(['permission:approval.decide', 'throttle:writes']);
Route::post('approvals/{id}/withdraw', [ApprovalController::class, 'withdraw'])
    ->middleware(['permission:approval.withdraw', 'throttle:writes']);

// "My approvals" from both sides: what I must decide, and what I am waiting on.
Route::get('me/approvals', [ApprovalController::class, 'index'])
    ->middleware('permission:approval.request');
