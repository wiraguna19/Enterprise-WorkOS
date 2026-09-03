<?php

declare(strict_types=1);

namespace App\Modules\Insights\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's request for one report as a file (ADR 0011).
 *
 * The only record Insights owns. Everything else in this module is a
 * computation over somebody else's tables; this is here because an export is a
 * request with a lifetime, and a lifetime needs somewhere to live.
 *
 * `requested_by_membership_id` is not decoration: it is the membership the job
 * binds with `runForMembership()`, which is what decides the file's contents.
 *
 * Column types are hand-maintained — the schema is raw SQL (docs/03 §0).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $requested_by_membership_id
 * @property string $report_key
 * @property string $format
 * @property array<string, mixed> $parameters
 * @property string $status
 * @property string|null $storage_path
 * @property string|null $filename
 * @property int|null $byte_size
 * @property int|null $row_count
 * @property int|null $hidden_count
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ReportExportModel extends TenantModel
{
    protected $table = 'report_exports';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'byte_size' => 'integer',
            'row_count' => 'integer',
            'hidden_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'requested_by_membership_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready'
            && $this->storage_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
