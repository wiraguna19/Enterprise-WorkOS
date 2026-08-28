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
 * sessions that are expired or revoked, which is how offboarding takes effect
 * within one request rather than at token expiry.
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
            ->where('token_hash', hash('sha256', $secret))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        return $session;
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

    public function revoke(string $reason = 'logout'): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
