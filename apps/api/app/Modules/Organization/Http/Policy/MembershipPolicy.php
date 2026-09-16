<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Platform\Domain\Tenancy\TenantContext;

/**
 * Per-record authorization: layer 4 of docs/06 §2.
 *
 * The tenant scope has already made other organizations' memberships
 * unreachable, so nothing here needs to re-check the organization — and
 * re-checking it would suggest the scope is optional.
 */
final class MembershipPolicy
{
    /**
     * The acting membership, looked up once.
     *
     * Every resource echoes three permission decisions, so a directory of 100
     * people asks this 300 times. Held on the policy, which the container keeps
     * for the length of the request and no longer — a cached actor that
     * outlived the request would answer with permissions the person has since
     * lost.
     */
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    /**
     * Everyone beneath the actor, resolved once per request.
     *
     * The directory asks `viewWorkload` for every row; without this the same
     * recursive query would run a hundred times to return the same list.
     *
     * Keyed by membership id for the same reason `$actorFor` is: one request
     * can act as two people, and a reporting line cached without its owner
     * would answer the second person with the first one's reports — which here
     * means showing them somebody else's workload.
     *
     * @var list<string>|null
     */
    private ?array $manages = null;

    private ?string $managesFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
        private readonly ReportingLine $reportingLine,
    ) {}

    public function view(UserModel $user, MembershipModel $membership): bool
    {
        return $this->can('person.view');
    }

    public function update(UserModel $user, MembershipModel $membership): bool
    {
        // People may always edit their own profile; editing someone else's is a
        // permission.
        return $this->isSelf($membership) || $this->can('person.update');
    }

    public function deactivate(UserModel $user, MembershipModel $membership): bool
    {
        // Deactivating yourself would lock the last admin out of the
        // organization; it is refused regardless of permission.
        return ! $this->isSelf($membership) && $this->can('person.deactivate');
    }

    /**
     * What this person may do, and where.
     *
     * The gate is about the SCOPED half. A person's org-wide roles are already
     * public to anyone who may view them — `PersonResource` sends them and the
     * profile renders them under "Access", which is how a viewer can tell who
     * the administrators are, and correct. Their GRANTS are different: each one
     * names a project, team or department somebody has authority over, and the
     * list of them is a map of the organization. `role.view` is trusted with
     * that map; `person.view` is not.
     *
     * So this endpoint is gated on the more sensitive half of what it returns,
     * which is the only safe way to gate one payload by two sensitivities.
     * Nothing stops it being widened later; it is harder to narrow.
     */
    public function viewRoles(UserModel $user, MembershipModel $membership): bool
    {
        return $this->can('role.view');
    }

    /**
     * Granting and revoking.
     *
     * Editing your OWN grants is refused whatever the permission, for the
     * reason `deactivate` refuses self: the person who can widen their own
     * authority without a second pair of eyes is the shape of an escalation,
     * and an administrator who genuinely needs it can be granted it by another
     * administrator.
     */
    public function manageRoles(UserModel $user, MembershipModel $membership): bool
    {
        return ! $this->isSelf($membership) && $this->can('role.manage');
    }

    public function viewWorkload(UserModel $user, MembershipModel $membership): bool
    {
        return $this->isSelf($membership)
            || $this->can('person.view_workload')
            || $this->managesTransitively($membership);
    }

    private function isSelf(MembershipModel $membership): bool
    {
        return (string) $membership->getKey() === $this->tenant->membershipId();
    }

    /**
     * A manager can see the workload of anyone in their reporting line, at any
     * depth, without needing the org-wide permission.
     *
     * Answered by `ReportingLine`, which is where this walk lives for the whole
     * product (ADR 0009). The version here climbed the chain by following
     * `$subject->manager` — a LAZY LOAD, inside a policy, called once per row
     * of the directory. Lazy loading is disabled outside production, so
     * `GET /people` threw a 500 for anyone the `||` above did not
     * short-circuit: every caller WITHOUT `person.view_workload`, which is to
     * say every ordinary employee. Managers and admins never saw it, and
     * neither did any test, because the seeded people driving them all hold
     * that permission.
     *
     * In production, where lazy loading is merely enabled, the same code was an
     * N+1 climbing up to ten levels per rendered row.
     */
    private function managesTransitively(MembershipModel $membership): bool
    {
        $actorId = $this->tenant->membershipId();

        // One query per request, not per row. `below()` is recursive SQL and
        // the directory asks this question for everybody on the page.
        if ($this->managesFor !== $actorId) {
            $this->manages = $this->reportingLine->below($actorId);
            $this->managesFor = $actorId;
        }

        return in_array((string) $membership->getKey(), $this->manages ?? [], strict: true);
    }

    private function can(string $permission): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }

    /**
     * Keyed by membership id, not merely memoized: one request can act as two
     * people — a console command binding a tenant per membership, or a test
     * that logs in twice — and a cache that ignored who it was for would answer
     * the second with the first one's permissions.
     */
    private function actor(): ?MembershipModel
    {
        $membershipId = $this->tenant->membershipId();

        if ($this->actorFor !== $membershipId) {
            $this->actor = MembershipModel::query()->with('employeeProfile')->find($membershipId);
            $this->actorFor = $membershipId;
        }

        return $this->actor;
    }
}
