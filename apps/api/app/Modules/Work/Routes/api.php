<?php

declare(strict_types=1);

use App\Modules\Work\Http\Controller\ActivityController;
use App\Modules\Work\Http\Controller\AssignmentController;
use App\Modules\Work\Http\Controller\MyTimeController;
use App\Modules\Work\Http\Controller\MyWorkController;
use App\Modules\Work\Http\Controller\ProjectController;
use App\Modules\Work\Http\Controller\TimeEntryController;
use App\Modules\Work\Http\Controller\WorkItemController;
use Illuminate\Support\Facades\Route;

/**
 * Endpoints mirror the domain, not the screens (docs/05 §1).
 *
 * Note what is NOT here: no /board-data, no /dashboard-cards. The Next.js BFF
 * composes UI-shaped responses so the API can stay domain-shaped.
 */

// ── My Work ─────────────────────────────────────────────────────────────────
// "My work" is real vocabulary in this system, so it earns endpoints.
Route::get('me/work', [MyWorkController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::get('me/work/counts', [MyWorkController::class, 'counts'])
    ->middleware('permission:work_item.view');
Route::get('me/work/needs-attention', [MyWorkController::class, 'needsAttention'])
    ->middleware('permission:work_item.view');

// Gated on seeing work, not on logging it: someone whose ability to log time
// was revoked still owns the hours they already logged, and a timesheet that
// empties out when a permission changes destroys the record it is for.
Route::get('me/time', [MyTimeController::class, 'index'])
    ->middleware('permission:work_item.view');

// ── Projects ────────────────────────────────────────────────────────────────
// The sidebar's pinned list (docs/08 §1, ADR 0044). Read on every
// authenticated request, so it is its own endpoint rather than a field on a
// heavier payload.
//
// BEFORE `projects/{key}` — `/me/projects` cannot collide with it, but the pin
// write below shares the `{key}` prefix and must be registered where Laravel
// will still match it.
Route::get('me/projects', [ProjectController::class, 'pinned'])
    ->middleware('permission:project.view');

Route::get('projects', [ProjectController::class, 'index'])
    ->middleware('permission:project.view');
Route::post('projects', [ProjectController::class, 'store'])
    ->middleware(['permission:project.create', 'throttle:writes']);
Route::get('projects/{key}', [ProjectController::class, 'show'])
    ->middleware('permission:project.view')->name('projects.show');
Route::get('projects/{key}/board', [ProjectController::class, 'board'])
    ->middleware('permission:project.view');

// A project could be created and never corrected (ADR 0040). `project.update`
// and `project.archive` were seeded in Phase 1, granted to roles, and answered
// by ProjectPolicy — with no route behind either. The policy is what hid it:
// a permission consulted by a policy looks consulted.
//
// `permission:project.view` rather than `project.update`, and the POLICY
// decides: a project's owner may correct their own project without holding the
// organization-wide permission, which is what ProjectPolicy has said since it
// was written. Guarding the route on `project.update` would overrule it — the
// coarse layer silently winning, which is docs/06 §2's named failure.
Route::patch('projects/{key}', [ProjectController::class, 'update'])
    ->middleware(['permission:project.view', 'throttle:writes']);
Route::post('projects/{key}/archive', [ProjectController::class, 'archive'])
    ->middleware(['permission:project.view', 'throttle:writes']);

// Project members (ADR 0041). `project_members` has decided project visibility
// since Phase 2 with no write path at all, so `visibility: private` was a
// one-way door: the creator became the only member and nobody could be added.
//
// Guarded on `project.view` with the POLICY deciding, like the edit above: a
// project's owner manages their own project's access without holding
// `project.manage_members` organization-wide.
// What happened to this project, and who did it (ADR 0043). Every write added
// by ADR 0040 and ADR 0041 records an activity entry, and nothing could read
// one: a write path with no read path, created by the slice that added the
// writes.
// Pinning is a personal act, not administration: anybody who can SEE a project
// may keep it in their own sidebar, and the policy `view` is the whole check.
Route::post('projects/{key}/pin', [ProjectController::class, 'setPinned'])
    ->middleware(['permission:project.view', 'throttle:writes']);

Route::get('projects/{key}/activity', [ActivityController::class, 'project'])
    ->middleware('permission:project.view');

Route::get('projects/{key}/members', [ProjectController::class, 'members'])
    ->middleware('permission:project.view');
Route::post('projects/{key}/members', [ProjectController::class, 'addMember'])
    ->middleware(['permission:project.view', 'throttle:writes']);
Route::patch('projects/{key}/members/{member}', [ProjectController::class, 'setMemberRole'])
    ->middleware(['permission:project.view', 'throttle:writes']);
Route::delete('projects/{key}/members/{member}', [ProjectController::class, 'removeMember'])
    ->middleware(['permission:project.view', 'throttle:writes']);

// ── Work items ──────────────────────────────────────────────────────────────
// Keyed by human reference (ENG-142) rather than UUID: it is what people paste
// into chat, and a readable URL is a small thing that makes a product feel
// considered (docs/08 §2).
Route::get('work-items', [WorkItemController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items', [WorkItemController::class, 'store'])
    ->middleware(['permission:work_item.create', 'throttle:writes']);
// BEFORE `work-items/{reference}`, or `fields` is read as a reference and the
// route resolver answers 404 for a path that exists. Laravel matches in
// registration order, which makes ordering a correctness concern and not a
// tidiness one.
Route::get('work-items/fields', [WorkItemController::class, 'fields'])
    ->middleware('permission:work_item.create');

Route::get('work-items/{reference}', [WorkItemController::class, 'show'])
    ->middleware('permission:work_item.view')->name('work-items.show');
Route::patch('work-items/{reference}', [WorkItemController::class, 'update'])
    ->middleware(['permission:work_item.update', 'throttle:writes']);
Route::delete('work-items/{reference}', [WorkItemController::class, 'destroy'])
    ->middleware(['permission:work_item.delete', 'throttle:writes']);

// State changes are ACTIONS, not field edits: they carry rules and side
// effects, and modelling them as PATCH pushes workflow logic into the client.
Route::post('work-items/{reference}/transition', [WorkItemController::class, 'transition'])
    ->middleware(['permission:work_item.transition', 'throttle:writes']);
Route::post('work-items/{reference}/move', [WorkItemController::class, 'move'])
    ->middleware(['permission:work_item.update', 'throttle:writes']);

// `work-items/{reference}/available-transitions` is NOT here. It lives in the
// Workflow module's routes, because answering it means evaluating the workflow
// graph and this module cannot do that. Registering it in both places meant
// whichever provider booted last silently won.

// ── Assignment ──────────────────────────────────────────────────────────────
Route::post('work-items/{reference}/assign', [AssignmentController::class, 'store'])
    ->middleware(['permission:work_item.assign', 'throttle:writes']);
// Accepting work assigned to YOU needs no permission — only identity.
Route::post('work-items/{reference}/accept', [AssignmentController::class, 'accept'])
    ->middleware('throttle:writes');
Route::delete('work-items/{reference}/assignees/{assignment}', [AssignmentController::class, 'destroy'])
    ->middleware(['permission:work_item.assign', 'throttle:writes']);
// The timeline. Behind `activity.view`, which until now was a permission with
// nothing behind it — granted to every role since Phase 1 and unreachable.
Route::get('work-items/{reference}/activity', [ActivityController::class, 'index'])
    ->middleware('permission:activity.view');

Route::get('work-items/{reference}/assignments', [AssignmentController::class, 'history'])
    ->middleware('permission:work_item.view');

// ── Time ────────────────────────────────────────────────────────────────────
// Reading time needs only the right to see the work; logging it is its own
// permission, because "may see this item" and "may put hours against it" are
// different questions (docs/06 §2).
Route::get('work-items/{reference}/time-entries', [TimeEntryController::class, 'index'])
    ->middleware('permission:work_item.view');
Route::post('work-items/{reference}/time-entries', [TimeEntryController::class, 'store'])
    ->middleware(['permission:work_item.log_time', 'throttle:writes']);
Route::delete('work-items/{reference}/time-entries/{entry}', [TimeEntryController::class, 'destroy'])
    ->middleware(['permission:work_item.log_time', 'throttle:writes']);
