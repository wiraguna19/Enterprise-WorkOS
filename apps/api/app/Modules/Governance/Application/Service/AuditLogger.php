<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Security and compliance events (docs/06 §3).
 *
 * Writes go through the query builder rather than a model because the table is
 * append-only at the database level: there is no update path to model, and an
 * Eloquent model would imply one exists.
 *
 * The email snapshot is stored deliberately — an audit trail must stay readable
 * after the account it describes is gone.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function record(
        string $event,
        array $metadata = [],
        ?Request $request = null,
        ?string $actorUserId = null,
        ?string $targetType = null,
        ?string $targetId = null,
    ): void {
        $request ??= request();

        DB::table('audit_logs')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->hasTenant() ? $this->tenant->organizationId() : null,
            'actor_user_id' => $actorUserId ?? $this->tenant->userId(),
            'actor_email_snapshot' => $this->actorEmail($actorUserId),
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => json_encode($this->redact($metadata), JSON_THROW_ON_ERROR),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    private function actorEmail(?string $actorUserId): string
    {
        // Same reasoning as ActivityLogger: the table, not the model, so this
        // module keeps depending only on Platform.
        $actorUserId ??= $this->tenant->userId();

        if ($actorUserId !== null) {
            return (string) (DB::table('users')->where('id', $actorUserId)->value('email') ?? '');
        }

        return 'system';
    }

    /**
     * Audit metadata is user-visible to administrators. Secrets never belong in
     * it, and "we will remember not to pass them" is not a control.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redact(array $metadata): array
    {
        $sensitive = ['password', 'password_confirmation', 'token', 'secret', 'mfa_code'];

        foreach ($metadata as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $sensitive, strict: true)) {
                $metadata[$key] = '[redacted]';
            }
        }

        return $metadata;
    }
}
