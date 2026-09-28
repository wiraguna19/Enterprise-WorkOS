<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\SessionNotFound;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tokens a person makes for a script or an integration (ADR 0049).
 *
 * A token IS a session row with `kind = 'api_token'`, so it acts as the person
 * who made it, in the organization they made it in, with exactly their
 * permissions — resolved on every request, like any session's. It cannot be
 * wider than its author, and the moment the author's membership ends, so does
 * every token they made.
 *
 * Personal, deliberately. A service account — a token that belongs to nobody —
 * is the feature people usually mean next, and it needs an answer to "whose
 * permissions, and who is accountable in the audit log" that this slice does
 * not invent. Named as owed in ADR 0049.
 */
final class ApiTokens
{
    /**
     * What a token may do, by the name the form shows.
     *
     * Two levels, not a scope per permission: a token already carries its
     * author's permissions, and a second, token-sized permission language
     * beside the role builder is two answers to one question — the defect
     * docs/06 §2 names. Read-only is the one cut worth making separately,
     * because "this script only reads" is a promise worth being able to keep.
     *
     * @var array<string, list<string>>
     */
    public const ACCESS = [
        'read' => ['read'],
        'read_write' => ['read', 'write'],
    ];

    /**
     * Days a token may live. A choice among three, not a free number: "never
     * expires" is the answer a free field gets, and a year is already long.
     *
     * @var list<int>
     */
    public const LIFETIMES = [30, 90, 365];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * This person's live tokens in this organization, newest first.
     *
     * @return Collection<int, SessionModel>
     */
    public function forUser(UserModel $user): Collection
    {
        return SessionModel::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $this->tenant->organizationId())
            ->where('kind', 'api_token')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Make a token and hand back its value — the only time it exists in the
     * clear. Only its SHA-256 digest is stored, like every session's.
     *
     * The secret carries a `wos_` prefix so a leaked one is recognisable in a
     * paste or a public repository, which is how secret scanners find other
     * vendors' keys.
     *
     * @return array{0: SessionModel, 1: string} the token row and its value
     */
    public function issue(UserModel $user, string $name, string $access, int $days, Request $request): array
    {
        $secret = 'wos_'.Str::random(48);

        $token = new SessionModel;
        $token->forceFill([
            'id' => SessionModel::newId(),
            'user_id' => $user->getKey(),
            'organization_id' => $this->tenant->organizationId(),
            'kind' => 'api_token',
            'token_hash' => hash('sha256', $secret),
            'name' => Str::limit(trim($name), 80, ''),
            'abilities' => self::ACCESS[$access],
            // Where it was MADE, kept for the audit question "who created this
            // and from where" — not where it will be used.
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'expires_at' => now()->addDays($days),
            // Never set. A token has not proved anything a password proves,
            // so the re-authentication window never opens for it.
            'reauthenticated_at' => null,
            'created_at' => now(),
        ])->save();

        $this->audit->record('auth.api_token_created', [
            'token_id' => $token->getKey(),
            'name' => $token->name,
            'access' => $access,
            'expires_in_days' => $days,
        ], $request);

        return [$token, $token->getKey().'|'.$secret];
    }

    /**
     * End one of your own tokens.
     *
     * Scoped to the person, the organization and the kind: a token id is a
     * uuid somebody could paste, and this must not become a way to end a
     * colleague's integration — or, through a session id, a colleague's login.
     */
    public function revoke(UserModel $user, string $tokenId, Request $request): void
    {
        $token = Str::isUuid($tokenId)
            ? SessionModel::query()
                ->where('id', $tokenId)
                ->where('user_id', $user->getKey())
                ->where('organization_id', $this->tenant->organizationId())
                ->where('kind', 'api_token')
                ->whereNull('revoked_at')
                ->first()
            : null;

        if (! $token instanceof SessionModel) {
            throw new SessionNotFound('That token is not one of yours, or has already been revoked.');
        }

        $token->revoke('api_token_revoked');

        $this->audit->record('auth.api_token_revoked', [
            'token_id' => $token->getKey(),
            'name' => $token->name,
        ], $request);
    }
}
