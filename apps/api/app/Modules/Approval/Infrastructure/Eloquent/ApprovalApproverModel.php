<?php

declare(strict_types=1);

namespace App\Modules\Approval\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who was ASKED. Explicit rather than derived from the work item's reviewer
 * assignment, because quorum needs a definite roster and "who was asked" must
 * survive a later reassignment.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $approval_id
 * @property string $membership_id
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable $created_at
 */
final class ApprovalApproverModel extends TenantModel
{
    protected $table = 'approval_approvers';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['notified_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }
}
