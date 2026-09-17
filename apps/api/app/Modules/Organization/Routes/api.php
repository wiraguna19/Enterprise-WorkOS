<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controller\DepartmentController;
use App\Modules\Organization\Http\Controller\InvitationController;
use App\Modules\Organization\Http\Controller\OrganizationSettingsController;
use App\Modules\Organization\Http\Controller\PersonController;
use App\Modules\Organization\Http\Controller\PersonRoleController;
use App\Modules\Organization\Http\Controller\TeamController;
use Illuminate\Support\Facades\Route;

/**
 * Endpoints are named after the domain, not after the screens that consume
 * them (docs/05 §1). `permission:` is the coarse gate; per-record checks live
 * in policies inside the controllers.
 *
 * **Four routes below carry no `permission:` gate on purpose** — the ones whose
 * policy asks a SCOPED question (ADR 0016). A coarse gate can only ask "do you
 * hold this across the organization", so leaving it in front of a scoped policy
 * refuses the grant before the policy is ever consulted: the weaker layer wins
 * and the feature silently does not exist. That is this codebase's
 * two-layers-disagree defect in its most confusing form, because every test of
 * the POLICY passes.
 *
 * They are not ungated. `auth:sanctum` and the tenant resolver still run, and
 * the policy is stricter than the gate it replaced.
 */
Route::get('departments', [DepartmentController::class, 'index'])
    ->middleware('permission:department.view');
Route::post('departments', [DepartmentController::class, 'store'])
    ->middleware(['permission:department.create', 'throttle:writes']);
// No `permission:` gate: DepartmentPolicy::update asks whether the actor holds
// `department.update` org-wide OR on THIS department.
Route::patch('departments/{department}', [DepartmentController::class, 'update'])
    ->middleware('throttle:writes');
Route::post('departments/{department}/move', [DepartmentController::class, 'move'])
    ->middleware('throttle:writes');

Route::get('teams', [TeamController::class, 'index'])
    ->middleware('permission:team.view');
Route::get('teams/{team}', [TeamController::class, 'show'])
    ->middleware('permission:team.view');
Route::post('teams', [TeamController::class, 'store'])
    ->middleware(['permission:team.create', 'throttle:writes']);
// Same: TeamPolicy::manageMembers asks the scoped question, so the coarse gate
// would refuse a team lead their own team before the policy could allow it.
Route::post('teams/{team}/members', [TeamController::class, 'addMember'])
    ->middleware('throttle:writes');
Route::delete('teams/{team}/members/{membership}', [TeamController::class, 'removeMember'])
    ->middleware('throttle:writes');

Route::get('people', [PersonController::class, 'index'])
    ->middleware('permission:person.view');
Route::get('people/{membership}', [PersonController::class, 'show'])
    ->middleware('permission:person.view');

// ── "Delete my data" (ADR 0022) ─────────────────────────────────────────────
// Behind `person.deactivate`, which has been granted to two roles since Phase 1
// with no route behind it. Not a DELETE: nothing is deleted. The person is
// taken out of the rows and the rows stay, because deleting them would delete
// the organization's history of work they did.
Route::post('people/{membership}/erase', [PersonController::class, 'erase'])
    ->middleware(['permission:person.deactivate', 'throttle:writes']);

// ── Unlocking somebody who has lost their phone (ADR 0031) ──────────────────
// Behind the same permission as erasure: it is already the key that means "I
// may take this person's access away", and a help desk trusted with that and
// not with unlocking somebody is a split nobody could defend. Never on
// yourself — the self-service path asks for a password for a reason.
Route::delete('people/{membership}/mfa', [PersonController::class, 'revokeMfa'])
    ->middleware(['permission:person.deactivate', 'throttle:writes']);

// ── Who may do what, and where (ADR 0016) ───────────────────────────────────
// A grant is always scoped to one project, team or department. Making an
// organization-wide administrator is a different act with a different blast
// radius, and it does not belong behind the same control.
// ── Inviting somebody in (ADR 0017) ─────────────────────────────────────────
// `person.invite` was granted to two roles in Phase 1 and had nothing behind it
// until now. The link is returned once, to the caller: there is no mail layer
// in this product and this slice does not invent one.
Route::post('people/invite', [InvitationController::class, 'store'])
    ->middleware(['permission:person.invite', 'throttle:writes']);
Route::get('invitations', [InvitationController::class, 'index'])
    ->middleware('permission:person.invite');
Route::delete('invitations/{id}', [InvitationController::class, 'destroy'])
    ->middleware(['permission:person.invite', 'throttle:writes']);

// The roles that exist, so a grant form cannot offer four keys to an
// organization that has five.
Route::get('roles', [PersonRoleController::class, 'catalogue'])
    ->middleware('permission:role.view');

// ── Roles a customer writes for themselves (ADR 0018) ───────────────────────
// Addressed by KEY: it is unique per organization, it is what a grant names,
// and it is what an audit log records.
Route::get('permissions', [PersonRoleController::class, 'permissions'])
    ->middleware('permission:role.view');
Route::get('roles/{key}', [PersonRoleController::class, 'showRole'])
    ->middleware('permission:role.view');
Route::post('roles', [PersonRoleController::class, 'storeRole'])
    ->middleware(['permission:role.manage', 'throttle:writes']);
Route::patch('roles/{key}', [PersonRoleController::class, 'updateRole'])
    ->middleware(['permission:role.manage', 'throttle:writes']);
Route::delete('roles/{key}', [PersonRoleController::class, 'destroyRole'])
    ->middleware(['permission:role.manage', 'throttle:writes']);
Route::get('people/{membership}/roles', [PersonRoleController::class, 'index'])
    ->middleware('permission:role.view');
Route::post('people/{membership}/roles', [PersonRoleController::class, 'store'])
    ->middleware(['permission:role.manage', 'throttle:writes']);
Route::delete('people/{membership}/roles/{assignment}', [PersonRoleController::class, 'destroy'])
    ->middleware(['permission:role.manage', 'throttle:writes']);

// ── Taking one permission away from one person (ADR 0020) ───────────────────
// Denials are administered by whoever administers roles: `role.manage` gates
// the writes and `role.view` the reader, so a deny model does not arrive with a
// fifth permission nothing else consults.
Route::post('people/{membership}/denials', [PersonRoleController::class, 'deny'])
    ->middleware(['permission:role.manage', 'throttle:writes']);
Route::delete('people/{membership}/denials/{denial}', [PersonRoleController::class, 'liftDenial'])
    ->middleware(['permission:role.manage', 'throttle:writes']);

// "Why can't I do that." Without this the refusal is a wall with no sign on it:
// neither the person hitting it nor the administrator they ask can tell a
// permission never granted from one taken away, and for what reason.
Route::get('people/{membership}/permissions/explain', [PersonRoleController::class, 'explain'])
    ->middleware('permission:role.view');

// ── The organization's own settings (ADR 0028) ──────────────────────────────
// Two permissions that have been ticked in the role builder since Phase 1 and
// asked about by nothing on the server. Reading the place you work and
// deciding when everybody in it is signed out are different acts, so they are
// different keys, as the catalogue already said they were.
Route::get('organization/settings', [OrganizationSettingsController::class, 'show'])
    ->middleware('permission:organization.view');
Route::patch('organization/settings/session-policy', [OrganizationSettingsController::class, 'updateSessionPolicy'])
    ->middleware(['permission:organization.manage_settings', 'throttle:writes']);
