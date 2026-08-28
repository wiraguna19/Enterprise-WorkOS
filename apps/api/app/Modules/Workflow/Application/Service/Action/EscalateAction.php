<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Notification\Application\Service\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Escalate up the reporting line.
 *
 * Walks `employee_profiles.manager_profile_id` upward by a configurable number
 * of levels, depth-capped so a cycle in the data cannot spin the worker. Used
 * by the overdue and stalled-approval rules.
 *
 * Notifies rather than reassigns, on purpose: silently moving someone's work to
 * their manager because it is late is a management decision, not an automation
 * one, and a tool that makes it for you gets switched off.
 */
final class EscalateAction implements WorkflowAction
{
    private const MAX_LEVELS = 4;

    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /** {@inheritDoc} */
    public function execute(
        array $config,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        $from = $facts['assignee_membership_id'] ?? $facts['created_by_membership_id'] ?? null;

        if ($from === null) {
            return ['skipped' => 'nobody to escalate from'];
        }

        $levels = min((int) ($config['levels'] ?? 1), self::MAX_LEVELS);
        $target = $this->climb((string) $from, $levels);

        if ($target === null) {
            return ['skipped' => 'no manager found in the reporting line'];
        }

        $delivered = $this->notifications->dispatch(
            type: 'work.escalated',
            subjectType: $subjectType,
            subjectId: $subjectId,
            recipients: [$target],
            payload: [
                'reference' => $facts['reference'] ?? null,
                'title' => $facts['title'] ?? null,
                'reason' => $config['reason'] ?? 'This work needs attention.',
            ],
            dedupeSeed: $causationId,
        );

        return ['escalated_to' => $target, 'delivered' => $delivered];
    }

    private function climb(string $membershipId, int $levels): ?string
    {
        $current = $membershipId;
        $seen = [$membershipId];

        for ($level = 0; $level < $levels; $level++) {
            $profile = DB::table('employee_profiles')
                ->where('membership_id', $current)
                ->first(['manager_profile_id']);

            if ($profile?->manager_profile_id === null) {
                // Reached the top of the chart before the requested level. The
                // person at the top is the right recipient, not nobody.
                return $current === $membershipId ? null : $current;
            }

            $next = DB::table('employee_profiles')
                ->where('id', $profile->manager_profile_id)
                ->value('membership_id');

            if ($next === null || in_array((string) $next, $seen, strict: true)) {
                return $current === $membershipId ? null : $current;   // cycle guard
            }

            $current = (string) $next;
            $seen[] = $current;
        }

        return $current === $membershipId ? null : $current;
    }
}
