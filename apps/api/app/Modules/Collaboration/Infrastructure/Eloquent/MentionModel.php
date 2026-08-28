<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $comment_id
 * @property string|null $mentioned_membership_id
 * @property string|null $mentioned_team_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
final class MentionModel extends TenantModel
{
    protected $table = 'mentions';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CommentModel, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommentModel::class, 'comment_id');
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'mentioned_membership_id');
    }
}
