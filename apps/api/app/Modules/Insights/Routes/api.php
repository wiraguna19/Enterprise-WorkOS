<?php

declare(strict_types=1);

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

Route::get('teams/{team}/workload', [WorkloadController::class, 'team'])
    ->middleware('permission:team.view');
