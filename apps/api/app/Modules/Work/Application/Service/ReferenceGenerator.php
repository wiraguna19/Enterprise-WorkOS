<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Produces ENG-142.
 *
 * A sequence per project (or per work type for project-less work), allocated
 * under a transaction-scoped advisory lock inside the caller's transaction. Two
 * people creating work at the same moment must not both get ENG-142 — and a
 * unique index alone would turn that race into a 500 rather than preventing it.
 */
final class ReferenceGenerator
{
    /** Prefixes for work that belongs to no project (docs/02 §5). */
    private const TYPE_PREFIXES = [
        'task' => 'TASK',
        'request' => 'REQ',
        'incident' => 'OPS',
        'review' => 'REV',
        'campaign' => 'CMP',
        'operational' => 'OPS',
        'approval_work' => 'APR',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function next(?string $projectId, string $type = 'task'): string
    {
        $prefix = $projectId !== null
            ? $this->projectKey($projectId)
            : (self::TYPE_PREFIXES[$type] ?? 'WORK');

        if (DB::transactionLevel() === 0) {
            // The lock below is transaction-scoped. Outside a transaction it
            // would be released the instant it is taken, so the sequence would
            // silently stop being safe rather than fail loudly.
            throw new LogicException(
                'ReferenceGenerator::next() must be called inside a transaction.',
            );
        }

        // An advisory lock rather than SELECT ... FOR UPDATE: PostgreSQL refuses
        // to lock the rows behind an aggregate ("FOR UPDATE is not allowed with
        // aggregate functions"), and there is no single row to lock anyway — the
        // thing being serialised is the *next value of a sequence*, which no row
        // owns. Keying it on (organization, prefix) means two tenants, or two
        // projects, never wait on each other.
        //
        // The lock is held until the caller's transaction commits, which is
        // exactly as long as the MAX+1 it protects stays unwritten.
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            $this->tenant->organizationId().'|'.$prefix,
        ]);

        // MAX + 1 over an indexed, tenant-scoped, prefix-matched set. The unique
        // index on (organization_id, reference) remains the backstop.
        $highest = DB::table('work_items')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('reference', 'like', $prefix.'-%')
            ->selectRaw("MAX(NULLIF(regexp_replace(reference, '^.*-', ''), '')::bigint) AS n")
            ->value('n');

        return $prefix.'-'.((int) $highest + 1);
    }

    private function projectKey(string $projectId): string
    {
        $key = DB::table('projects')
            ->where('id', $projectId)
            ->where('organization_id', $this->tenant->organizationId())
            ->value('key');

        return (string) ($key ?? 'WORK');
    }
}
