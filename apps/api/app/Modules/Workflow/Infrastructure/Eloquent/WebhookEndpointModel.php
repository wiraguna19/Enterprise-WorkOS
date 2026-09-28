<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * An address this organization has agreed to send events to (ADR 0048).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $url
 * @property string $secret_encrypted
 * @property bool $is_active
 * @property int $failure_count
 * @property string|null $disabled_reason
 * @property string|null $created_by_membership_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WebhookEndpointModel extends TenantModel
{
    protected $table = 'webhook_endpoints';

    /**
     * Never serialised, by anything.
     *
     * Hidden rather than trusted to every caller's `present()`: the one place a
     * secret leaks is the place somebody returned a model straight from a
     * controller because it was quicker.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_encrypted'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
