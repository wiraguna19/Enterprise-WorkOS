<?php

declare(strict_types=1);

namespace App\Modules\Approval\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only at the database level. There is deliberately no update path on
 * this model — a decision the application can rewrite is not a decision.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $approval_id
 * @property string $reviewer_membership_id
 * @property string $decision
 * @property string $comment
 * @property CarbonImmutable $decided_at
 */
final class ApprovalDecisionModel extends TenantModel
{
    protected $table = 'approval_decisions';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'reviewer_membership_id');
    }
}
