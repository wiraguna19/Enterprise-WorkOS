<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * The absence of a row means "the default for this type" — so adding a
 * notification type never requires backfilling a row per member per org.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $type
 * @property bool $in_app
 * @property bool $email
 * @property string $digest
 * @property CarbonImmutable $updated_at
 */
final class NotificationPreferenceModel extends TenantModel
{
    protected $table = 'notification_preferences';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
