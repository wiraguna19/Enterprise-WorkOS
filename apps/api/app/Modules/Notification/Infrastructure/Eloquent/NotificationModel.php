<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $type
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $actor_membership_id
 * @property array<string, mixed> $payload
 * @property string $dedupe_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $emailed_at
 * @property CarbonImmutable $created_at
 */
final class NotificationModel extends TenantModel
{
    protected $table = 'notifications';

    public $timestamps = false;

    /** Internal; the inbox has no use for it and it would only invite abuse. */
    protected $hidden = ['dedupe_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'actor_membership_id');
    }

    /** @param Builder<NotificationModel> $query
     *  @return Builder<NotificationModel> */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at')->whereNull('archived_at');
    }

    /** @param Builder<NotificationModel> $query
     *  @return Builder<NotificationModel> */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->orderByDesc('created_at');
    }
}
