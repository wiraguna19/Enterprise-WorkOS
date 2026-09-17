<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\MultiFactor;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Application\Service\PersonErasure;
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
    public function __construct(
        private readonly PersonErasure $erasure,
        private readonly MultiFactor $mfa,
    ) {}

    /**
     * Erase a person from this organization (ADR 0022).
     *
     * A POST rather than a DELETE, and the verb is the honest one: nothing is
     * deleted. The person goes out of the rows and the rows stay, because a
     * hard delete would take a year of the organization's work with them and
     * silently change every report computed from it.
     *
     * Gated by `person.deactivate` — a permission granted since Phase 1 with no
     * route behind it, and a policy method with nothing routing to it either.
     */
    public function erase(Request $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('erase', $membership);

        return $this->ok($this->erasure->erase($membership, $request));
    }

    /**
     * Unlock somebody who has lost both their phone and their recovery codes
     * (ADR 0031).
     *
     * Until this existed the answer was a row in `psql`, which is not an answer
     * — it was found by somebody locking themselves out of the seed data an
     * hour after two-factor shipped.
     */
    public function revokeMfa(Request $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('revokeMfa', $membership);

        $user = $membership->user;

        if ($user === null) {
            // A membership whose user is gone: nothing to unlock, and a 404 is
            // the honest answer rather than a 500 from a null.
            abort(404);
        }

        $this->mfa->revokeFor($user, $request);

        return $this->noContent();
    }

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
