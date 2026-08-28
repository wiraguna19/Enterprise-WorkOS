<?php

declare(strict_types=1);

use App\Modules\Collaboration\Http\Controller\CommentController;
use Illuminate\Support\Facades\Route;

Route::get('work-items/{reference}/comments', [CommentController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items/{reference}/comments', [CommentController::class, 'store'])
    ->middleware(['permission:comment.create', 'throttle:writes']);
Route::patch('comments/{id}', [CommentController::class, 'update'])
    ->middleware(['permission:comment.update_own', 'throttle:writes']);
