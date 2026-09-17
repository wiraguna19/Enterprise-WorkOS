<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\Concerns\HasUuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An opaque, revocable session.
 *
 * Extends Sanctum's token model but stores the SHA-256 digest in `token_hash`
 * rather than `token`, and adds the organization binding, so the tenant a
 * session acts on is a property of the SERVER-side row — the client cannot
 * name it (docs/06 §1).
 *
 * `findToken` is overridden to look up the renamed column and to reject
 * sessions that are expired, revoked, or idle past what the organization allows
 * (ADR 0029), which is how offboarding takes effect within one request rather
 * than at token expiry.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $organization_id
 * @property string $token_hash
 * @property string $name
 * @property array<string, mixed> $abilities
 * @property mixed $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonImmutable $created_at
 */
final class SessionModel extends PersonalAccessToken
{
    // Sanctum's PersonalAccessToken does not extend the project's BaseModel, so
    // the v7-UUID key behaviour every other table relies on must be pulled in
    // explicitly here (docs/03 §0).
    use HasUuidV7;

    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function findToken($token): ?static
    {
        // Sanctum's plain-text format is "<id>|<secret>"; we only ever compare
        // digests, so a database leak does not yield usable session tokens.
        $secret = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;

        /** @var static|null $session */
        $session = self::query()
            ->select('sessions.*')
            // The organization's idle window, carried back by the SAME query
            // (ADR 0029). This runs on every authenticated request in the
            // product, so a second lookup here would be a query added to every
            // page in exchange for a setting most organizations leave off.
            //
            // LEFT, because `sessions.organization_id` is nullable: a session
            // bound to no organization has no policy to answer to.
            ->leftJoin('organizations', 'organizations.id', '=', 'sessions.organization_id')
            ->addSelect('organizations.idle_timeout_minutes')
            // UNQUALIFIED, and it has to stay that way: larastan resolves a
            // column name against this model's table, and `sessions.token_hash`
            // is not a property it can find — qualifying them fails the
            // analysis. None of the three exists on `organizations`, so there
            // is nothing here for Postgres to call ambiguous. A column added
            // there with one of these names would break this query, which is
            // the trade being made knowingly.
            ->where('token_hash', hash('sha256', $secret))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null) {
            return null;
        }

        return $session->hasGoneIdle() ? null : $session;
    }

    /**
     * Has nobody used this session for longer than the organization allows?
     *
     * Ends the session on the spot rather than merely refusing it. A row that
     * authenticates nobody and still reads as live in Settings → Signed in is
     * the product lying about the thing that screen exists to answer, and the
     * REASON is the whole point of `revoked_reason` (ADR 0023): "signed out for
     * inactivity" is precisely what somebody asks about the next morning.
     *
     * `last_used_at` is Sanctum's, written on every authenticated request since
     * Phase 1 and — until this — read only to print a date on the session list.
     * A session issued and never used falls back to when it was created, so
     * one that was never touched still ages out.
     */
    public function hasGoneIdle(): bool
    {
        // Carried by the join in `findToken`, not a column on this table. It
        // arrives as an original attribute rather than a dirty one, so the
        // `save()` below writes `revoked_at` and `revoked_reason` alone.
        $minutes = $this->getAttribute('idle_timeout_minutes');

        if (! is_numeric($minutes)) {
            return false;
        }

        $since = $this->last_used_at ?? $this->created_at;

        if ($since->addMinutes((int) $minutes)->isFuture()) {
            return false;
        }

        $this->revoke('idle_timeout');

        return true;
    }

    /**
     * Always a user, never a polymorphic anything.
     *
     * Sanctum declares this as a MorphTo because a token may belong to any
     * model. Here it cannot: a session is bound to a person and to an
     * organization (docs/06 §1), so this narrows to a plain belongsTo. The
     * signature stays untyped because BelongsTo does not satisfy the parent's
     * MorphTo return type, and widening the parent is not ours to do.
     *
     * @return BelongsTo<UserModel, $this>
     */
    public function tokenable()
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /**
     * End this session, and say why.
     *
     * The reason was a parameter that went nowhere from Phase 1 until Phase 7:
     * every caller passed one and the method wrote `revoked_at` alone. It
     * matters in exactly the situation revoked rows are kept for — somebody
     * asking why they were signed out, days later, and the difference between
     * "you did it from your phone" and "an administrator did it" being the
     * whole of the answer (ADR 0023).
     */
    public function revoke(string $reason = 'logout'): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();
    }
}
