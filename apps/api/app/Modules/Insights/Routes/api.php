<?php

declare(strict_types=1);

use App\Modules\Insights\Http\Controller\AtRiskController;
use App\Modules\Insights\Http\Controller\FlowController;
use App\Modules\Insights\Http\Controller\ProjectHealthController;
use App\Modules\Insights\Http\Controller\ReportController;
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

// Manager Home's capacity block: the caller's own reporting line. Gated on
// nothing org-wide — the scope IS the caller's line, and MembershipPolicy::
// viewWorkload still decides each row (ADR 0009).
Route::get('insights/my-reports/workload', [WorkloadController::class, 'reports']);

// Flow: how work is moving, by period and by project — never by person
// (ADR 0007). Gated on report.view rather than on seeing any one record: this
// is an organization-level number, and the drill-through applies each reader's
// own visibility to the records behind it.
Route::get('insights/flow', [FlowController::class, 'index'])
    ->middleware('permission:report.view');

Route::get('insights/flow/items', [FlowController::class, 'items'])
    ->middleware('permission:report.view');

// Where work waited, by state category (ADR 0010). Its own route because it
// reads every transition in the window rather than folding the completions.
Route::get('insights/bottlenecks', [FlowController::class, 'bottlenecks'])
    ->middleware('permission:report.view');

// Project health: five signals with their counts, and the records behind each
// (ADR 0008). Gated on `project.view` — health is a fact about a project, and
// who may see WHICH project is decided per record by the visibility scope and
// ProjectPolicy, not by a reporting permission. Someone who can open the
// project can see how it is doing.
Route::get('insights/projects/{key}/health', [ProjectHealthController::class, 'show'])
    ->middleware('permission:project.view');

Route::get('insights/projects/{key}/health/items', [ProjectHealthController::class, 'items'])
    ->middleware('permission:project.view');

// Manager Home's risk list (ADR 0009). No permission gates it and no role
// selects it: the scope is the caller's own reporting line and the projects
// they own or manage, so someone with neither gets an empty list — which is
// how the page knows not to render Manager Home at all.
Route::get('insights/at-risk', [AtRiskController::class, 'index'])
    ->middleware('permission:work_item.view');

// The four reports and their exports (docs/05, ADR 0011). `report.view` reads
// one; `report.export` asks for a file, and the file is built with the
// requester's own visibility rather than the worker's.
Route::get('reports/exports', [ReportController::class, 'index'])
    ->middleware('permission:report.export');

Route::get('reports/exports/{id}/download', [ReportController::class, 'download'])
    ->middleware('permission:report.export');

Route::get('reports/{key}', [ReportController::class, 'show'])
    ->middleware('permission:report.view');

// The five-per-hour limit docs/05 §6 asks for is enforced INSIDE the
// controller, not with `throttle:` — the framework's throttle middleware runs
// before this product's tenant resolver, so a per-organization key is not
// available to it (see ReportController::withinRateLimit).
Route::post('reports/{key}/export', [ReportController::class, 'export'])
    ->middleware('permission:report.export');
