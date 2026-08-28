<?php

declare(strict_types=1);

use App\Modules\Insights\Http\Controller\FlowController;
use App\Modules\Insights\Http\Controller\WorkloadController;
use Illuminate\Support\Facades\Route;

/**
 * Workload is read through the person and the team it is about, rather than
 * through a /workload collection: it is an attribute of someone's week, not a
 * record of its own (docs/05 §1).
 *
 * The route permission is the coarse one; who may see WHOSE workload is decided
 * per record by MembershipPolicy::viewWorkload — which is how a manager reaches
 * their reporting line without holding the organization-wide ability.
 */
Route::get('people/{membership}/workload', [WorkloadController::class, 'person'])
    ->middleware('permission:person.view');

// The drill-through docs/10 requires: a number a user cannot open is a number
// they have to take on trust, and this product does not ask for that.
Route::get('people/{membership}/workload/items', [WorkloadController::class, 'personItems'])
    ->middleware('permission:person.view');

Route::get('teams/{team}/workload', [WorkloadController::class, 'team'])
    ->middleware('permission:team.view');

// Flow: how work is moving, by period and by project — never by person
// (ADR 0007). Gated on report.view rather than on seeing any one record: this
// is an organization-level number, and the drill-through applies each reader's
// own visibility to the records behind it.
Route::get('insights/flow', [FlowController::class, 'index'])
    ->middleware('permission:report.view');

Route::get('insights/flow/items', [FlowController::class, 'items'])
    ->middleware('permission:report.view');
