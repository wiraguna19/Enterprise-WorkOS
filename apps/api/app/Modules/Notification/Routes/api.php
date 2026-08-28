<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controller\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('notifications/read', [NotificationController::class, 'markRead'])
    ->middleware('throttle:writes');

Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences'])
    ->middleware(['permission:notification.manage_own', 'throttle:writes']);
