<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * One event on its way to one endpoint, and what happened to it (ADR 0048).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $endpoint_id
 * @property string $event
 * @property string $dedupe_key
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WebhookDeliveryModel extends TenantModel
{
    protected $table = 'webhook_deliveries';

    /** @var list<string> */
    public const STATUSES = ['pending', 'delivered', 'abandoned', 'refused'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
