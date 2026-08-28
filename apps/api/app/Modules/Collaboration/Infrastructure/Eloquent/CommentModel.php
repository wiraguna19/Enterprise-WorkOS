<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Polymorphic over subjects so the same machinery serves work items, projects,
 * and later documents and approvals — without a second implementation.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $commentable_type
 * @property string $commentable_id
 * @property string|null $parent_id
 * @property string|null $author_membership_id
 * @property string $body_markdown
 * @property string $body_html
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class CommentModel extends TenantModel
{
    use SoftDeletes;

    protected $table = 'comments';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'author_membership_id');
    }

    /** @return HasMany<CommentModel, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    /** @return HasMany<MentionModel, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(MentionModel::class, 'comment_id');
    }
}
