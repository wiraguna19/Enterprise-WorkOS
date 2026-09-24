<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Organization\Infrastructure\Eloquent\OrganizationModel;

/**
 * Writes for the settings that govern everybody here (ADR 0046).
 *
 * These two writes sat in the controller, and of all the writes this codebase
 * had put there they are the ones that most needed a record: requiring a second
 * factor confines every person without one to four endpoints, and shortening
 * the session window signs devices out. Both were answerable only by reading
 * the column afterwards — the row said what the policy IS and nothing said who
 * changed it, or from what.
 *
 * So the entry is written here, beside the write, rather than left to each
 * caller to remember. That is the lesson the department rename taught: a
 * partial record is worse than none, because an empty history looks unfinished
 * while a history missing one act looks complete (ADR 0045).
 */
final class OrganizationSettingsService
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    public function setMfaRequired(OrganizationModel $organization, bool $required): OrganizationModel
    {
        $was = (bool) $organization->require_mfa;

        $organization->forceFill(['require_mfa' => $required])->save();

        // Unchanged is not an event. A person pressing save on a form they did
        // not edit would otherwise fill the history with acts nobody performed.
        if ($was !== $required) {
            $this->activity->record('organization', (string) $organization->getKey(), 'organization.mfa_policy_changed', [
                'from' => $was,
                'to' => $required,
            ]);
        }

        return $organization;
    }

    /**
     * @param  array{session_lifetime_days: int, idle_timeout_minutes?: int|null}  $changes
     */
    public function setSessionPolicy(OrganizationModel $organization, array $changes): OrganizationModel
    {
        $diff = [];

        foreach ($changes as $column => $value) {
            $was = $organization->getAttribute($column);

            if ($was !== $value) {
                $diff[$column] = ['from' => $was, 'to' => $value];
            }
        }

        $organization->forceFill($changes)->save();

        if ($diff !== []) {
            $this->activity->record(
                'organization',
                (string) $organization->getKey(),
                'organization.session_policy_changed',
                $diff,
            );
        }

        return $organization;
    }
}
