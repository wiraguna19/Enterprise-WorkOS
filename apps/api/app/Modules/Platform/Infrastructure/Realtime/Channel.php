<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Realtime;

/**
 * The channel names, in one place (docs/07 §8).
 *
 * Every name starts with the organization, because a channel name is the first
 * thing an authorization callback matches on and a name that did not carry the
 * tenant would make every such callback look it up instead — which is the kind
 * of check that gets forgotten in exactly one of the places it is needed.
 */
final class Channel
{
    /** One person, inside one organization: their notifications. */
    public static function user(string $organizationId, string $userId): string
    {
        return "org.{$organizationId}.user.{$userId}";
    }

    /** One work item: comments, status, assignment. */
    public static function workItem(string $organizationId, string $workItemId): string
    {
        return "org.{$organizationId}.work-item.{$workItemId}";
    }

    /** Who is looking at a work item right now. */
    public static function workItemPresence(string $organizationId, string $workItemId): string
    {
        return "presence-org.{$organizationId}.work-item.{$workItemId}";
    }

    /** A project board: cards other people move. */
    public static function board(string $organizationId, string $projectId): string
    {
        return "org.{$organizationId}.project.{$projectId}.board";
    }
}
