<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Realtime\Application\ChannelGuard;
use Illuminate\Support\Facades\Broadcast;

/**
 * Channel registration (docs/07 §8).
 *
 * Names here, decisions in ChannelGuard. This file is a routing table: it says
 * which patterns exist and nothing about who may hear them, so the security
 * rules live somewhere they can be called and tested without a broadcaster.
 */
Broadcast::channel(
    'org.{organizationId}.user.{userId}',
    static fn (UserModel $user, string $organizationId, string $userId): bool => app(ChannelGuard::class)
        ->mayHearUser($user, $organizationId, $userId),
);

Broadcast::channel(
    'org.{organizationId}.work-item.{workItemId}',
    static fn (UserModel $user, string $organizationId, string $workItemId): bool => app(ChannelGuard::class)
        ->mayHearWorkItem($user, $organizationId, $workItemId),
);

Broadcast::channel(
    'presence-org.{organizationId}.work-item.{workItemId}',
    static fn (UserModel $user, string $organizationId, string $workItemId): array|false => app(ChannelGuard::class)
        ->presenceIdentity($user, $organizationId, $workItemId),
);

Broadcast::channel(
    'org.{organizationId}.project.{projectId}.board',
    static fn (UserModel $user, string $organizationId, string $projectId): bool => app(ChannelGuard::class)
        ->mayHearBoard($user, $organizationId, $projectId),
);
