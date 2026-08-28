<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Application;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * Who may listen to what (docs/06 §2, docs/07 §8).
 *
 * A class rather than closures in routes/channels.php, for one reason that
 * matters more than tidiness: under the `null` broadcaster — the default, and
 * what the test suite runs — Laravel's auth endpoint does nothing at all and
 * answers 200 to everything. Channel authorization tested through HTTP would
 * therefore prove nothing while looking thorough. Here it is ordinary code with
 * ordinary tests.
 *
 * The rule every method follows: a subscription must be at least as hard to
 * obtain as the HTTP request for the same data. Anything looser turns the
 * socket into a way around visibility, which is the one thing a real-time layer
 * must never become.
 *
 * Channel names are attacker-supplied. Every method therefore takes the
 * organization from the NAME and proves the caller belongs to it, rather than
 * assuming the tenant that resolved the request.
 */
final class ChannelGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
        private readonly WorkItemVisibility $visibility,
    ) {}

    /**
     * Someone's own notification stream.
     *
     * Two conditions, not one: it must be you, AND you must currently be an
     * active member of that organization. Identity alone is not enough — a
     * revoked membership still belongs to a real user, who would otherwise keep
     * listening to an organization that removed them.
     */
    public function mayHearUser(UserModel $user, string $organizationId, string $userId): bool
    {
        return (string) $user->getKey() === $userId
            && $this->activeMembership($user, $organizationId) !== null;
    }

    /**
     * A work item's comments, status, and assignment.
     *
     * Authorized through the same visibility rule the REST endpoint uses rather
     * than a reimplementation of it: an item invisible over HTTP must be
     * unsubscribable over the socket, and two copies of that rule is one copy
     * that drifts.
     */
    public function mayHearWorkItem(UserModel $user, string $organizationId, string $workItemId): bool
    {
        $membership = $this->activeMembership($user, $organizationId);

        if ($membership === null) {
            return false;
        }

        // Bound to the SUBSCRIBER's membership, so the rule answers for them
        // rather than for whoever the request happened to authenticate as.
        return $this->tenant->runForMembership(
            $organizationId,
            (string) $membership->getKey(),
            function () use ($workItemId): bool {
                $query = WorkItemModel::query()->whereKey($workItemId)->whereNull('deleted_at');

                $this->visibility->apply($query);

                return $query->exists();
            },
        );
    }

    /**
     * Who else is looking at this item.
     *
     * Returns the identity every other subscriber will see, or false. Kept to a
     * name and an id, never an email: presence data is handed to everyone else
     * on the channel, so it is a broadcast of whatever it contains.
     *
     * @return array{id: string, name: string}|false
     */
    public function presenceIdentity(UserModel $user, string $organizationId, string $workItemId): array|false
    {
        if (! $this->mayHearWorkItem($user, $organizationId, $workItemId)) {
            return false;
        }

        $membership = $this->activeMembership($user, $organizationId);

        return $membership === null ? false : [
            'id' => (string) $membership->getKey(),
            'name' => (string) $user->name,
        ];
    }

    /**
     * A project board: cards other people move.
     *
     * Gated on the project rather than on each card, because that is how the
     * board endpoint is gated — someone who may open the board may watch it
     * change.
     */
    public function mayHearBoard(UserModel $user, string $organizationId, string $projectId): bool
    {
        $membership = $this->activeMembership($user, $organizationId);

        if ($membership === null) {
            return false;
        }

        // Resolved, not assumed away: passing `false` here would quietly deny
        // the board to everyone who can see internal projects by permission
        // rather than by membership.
        $canViewAll = $this->permissions->has($membership, 'project.view_all');
        $membershipId = (string) $membership->getKey();

        return $this->tenant->runForMembership(
            $organizationId,
            $membershipId,
            static fn (): bool => ProjectModel::query()
                ->visibleTo($membershipId, $canViewAll)
                ->whereKey($projectId)
                ->whereNull('deleted_at')
                ->exists(),
        );
    }

    /**
     * Looked up without the tenant scope on purpose: this runs BEFORE any
     * tenant is established for the channel being asked about, which is exactly
     * what it is deciding.
     */
    private function activeMembership(UserModel $user, string $organizationId): ?MembershipModel
    {
        return $this->tenant->runAsPlatform(
            'realtime.channel_authorization',
            static fn (): ?MembershipModel => MembershipModel::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->first(),
        );
    }
}
