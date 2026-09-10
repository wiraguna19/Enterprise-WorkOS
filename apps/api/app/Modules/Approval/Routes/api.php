<?php

declare(strict_types=1);

use App\Modules\Approval\Http\Controller\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('approvals', [ApprovalController::class, 'index'])
    ->middleware('permission:approval.decide');
// Both sides of a submission read this one: the reviewer to decide it, the
// requester to see what they sent and whether it is still pending. Gated on
// `decide` alone, the requester got a 403 from the route while
// `ApprovalPolicy::view` named them as a participant — and could still withdraw
// the thing they were not allowed to read.
Route::get('approvals/{id}', [ApprovalController::class, 'show'])
    ->middleware('permission.any:approval.decide,approval.request');
Route::post('approvals/{id}/decide', [ApprovalController::class, 'decide'])
    ->middleware(['permission:approval.decide', 'throttle:writes']);
Route::post('approvals/{id}/withdraw', [ApprovalController::class, 'withdraw'])
    ->middleware(['permission:approval.withdraw', 'throttle:writes']);

// "My approvals" from both sides: what I must decide, and what I am waiting on.
Route::get('me/approvals', [ApprovalController::class, 'index'])
    ->middleware('permission:approval.request');
