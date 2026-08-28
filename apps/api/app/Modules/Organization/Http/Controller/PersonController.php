<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Http\Resource\PersonResource;
use App\Modules\Organization\Infrastructure\Eloquent\EmployeeProfileModel;
use App\Modules\Platform\Application\Query\CursorPage;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

final class PersonController extends ApiController
{
    public function index(Request $request): ApiResponse
    {
        $query = MembershipModel::query()
            // Eager loaded, always: the resource reads user, profile, and
            // department for every row. Lazy loading here would be N+1 across
            // three relations and the model layer throws outside production.
            ->with(['user', 'employeeProfile.department'])
            ->when(
                $request->filled('filter.status'),
                fn (Builder $q) => $q->where('status', $request->input('filter.status')),
                fn (Builder $q) => $q->where('status', 'active'),
            )
            ->when($request->filled('filter.department_id'), fn (Builder $q) => $q
                ->whereHas('employeeProfile', function (Builder $profiles) use ($request): void {
                    // Named and typed rather than an arrow function: the column
                    // check only happens once the builder's model is known, and
                    // department_id belongs to the profile, not to a Model.
                    /** @var Builder<EmployeeProfileModel> $profiles */
                    $profiles->where('department_id', $request->input('filter.department_id'));
                }))
            ->when($request->filled('q'), fn (Builder $q) => $q
                ->whereHas('user', fn (Builder $u) => $u
                    ->whereRaw('lower(name) like ?', ['%'.mb_strtolower((string) $request->input('q')).'%'])))
            ->orderBy('id');

        $page = new CursorPage(
            $query->cursorPaginate(CursorPage::perPage($request->integer('limit')))
        );

        return ApiResponse::collection(
            PersonResource::collection($page->paginator->items()),
            $page->meta(),
        );
    }

    public function show(MembershipModel $membership): ApiResponse
    {
        $this->authorize('view', $membership);

        // Named one level deeper than looks necessary: the resource links the
        // manager and each report by MEMBERSHIP id, and reads their names, so
        // stopping at `manager` would leave the resource lazy loading inside a
        // map — an N+1 that only shows up on a manager with a large team.
        $membership->load([
            'user',
            'roles',
            'employeeProfile.department',
            'employeeProfile.manager.membership.user',
            'employeeProfile.directReports.membership.user',

            // Reporting lines outlive the people on them: a profile that still
            // lists someone who left reads as stale data rather than history.
            'employeeProfile.directReports' => fn (HasMany $reports) => $reports
                ->whereHas('membership', function (Builder $memberships): void {
                    // Named and typed for the same reason the department filter
                    // above is: `status` belongs to a membership, and a bare
                    // Builder is a Builder of Model, which has no columns.
                    /** @var Builder<MembershipModel> $memberships */
                    $memberships->where('status', 'active');
                }),
        ]);

        return $this->ok(PersonResource::detail($membership));
    }
}
