<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Notification\Application\Service\NotificationDispatcher;

/**
 * Notify a resolved audience.
 *
 * Recipients are named by ROLE relative to the subject — assignee, reviewer,
 * creator, watchers, manager — never by membership id. A rule that hardcodes a
 * person breaks the day they change teams, and every customer eventually
 * discovers that the hard way.
 */
final class NotifyAction implements WorkflowAction
{
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
        $recipients = $this->notifications->resolveAudience(
            array_values(array_map(strval(...), (array) ($config['to'] ?? ['assignee']))),
            $subjectType,
            $subjectId,
        );

        $sent = $this->notifications->dispatch(
            type: (string) ($config['notification_type'] ?? 'workflow.rule'),
            subjectType: $subjectType,
            subjectId: $subjectId,
            recipients: $recipients,
            payload: [
                'message' => $config['message'] ?? null,
                'reference' => $facts['reference'] ?? null,
                'title' => $facts['title'] ?? null,
            ],
            // The causation id is the dedupe seed: the same rule firing twice
            // for the same change produces the same key and the second insert
            // is a no-op.
            dedupeSeed: $causationId,
        );

        return ['recipients' => count($recipients), 'delivered' => $sent];
    }
}
