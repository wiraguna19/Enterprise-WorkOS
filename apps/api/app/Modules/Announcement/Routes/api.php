<?php

declare(strict_types=1);

use App\Modules\Announcement\Http\Controller\AnnouncementController;
use Illuminate\Support\Facades\Route;

/*
 * Announcements (ADR 0061). Reading needs no permission; the service decides
 * what is addressed to whom.
 *
 * Publishing is not gated here either, and that is deliberate rather than
 * forgotten: `announcement.publish` granted ON one team is a way to be allowed,
 * and route middleware only knows organization-wide permissions. A gate here
 * would refuse exactly the person a scoped grant was written for. The one
 * answer is AnnouncementAuthority's.
 */
Route::get('announcements', [AnnouncementController::class, 'index'])
    ->name('announcements.index');

Route::get('announcements/audiences', [AnnouncementController::class, 'audiences'])
    ->name('announcements.audiences');

Route::post('announcements/read', [AnnouncementController::class, 'markRead'])
    ->name('announcements.read');

Route::post('announcements', [AnnouncementController::class, 'store'])
    ->middleware('throttle:writes')
    ->name('announcements.store');

Route::get('announcements/{id}', [AnnouncementController::class, 'show'])
    ->whereUuid('id')
    ->name('announcements.show');

// Not gated on a permission: the author may always change or retract what
// they said (ADR 0061), and the service checks that.
Route::patch('announcements/{id}', [AnnouncementController::class, 'update'])
    ->whereUuid('id')
    ->middleware('throttle:writes')
    ->name('announcements.update');

Route::delete('announcements/{id}', [AnnouncementController::class, 'destroy'])
    ->whereUuid('id')
    ->name('announcements.destroy');

Route::post('announcements/{id}/acknowledge', [AnnouncementController::class, 'acknowledge'])
    ->whereUuid('id')
    ->name('announcements.acknowledge');
