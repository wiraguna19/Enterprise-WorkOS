<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global identity. NOT tenant-scoped: one human, one account, many
 * organizations — which is what makes the SaaS path free later (docs/03 §1).
 *
 * Users are never hard-deleted. `deactivated_at` plus revoked memberships is
 * the offboarding path, so every activity log and assignment history stays
 * readable afterwards.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string|null $password_hash
 * @property string|null $avatar_path
 * @property string $timezone
 * @property string $locale
 * @property bool $is_platform_admin
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $mfa_secret_encrypted
 * @property CarbonImmutable|null $mfa_enabled_at
 * @property array<string, mixed>|null $mfa_recovery_codes
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class UserModel extends BaseModel implements AuthenticatableContract
{
    use Authenticatable;
    use Authorizable;
    use HasApiTokens;
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $hidden = ['password_hash', 'mfa_secret_encrypted', 'mfa_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_platform_admin' => 'boolean',
            'mfa_recovery_codes' => 'array',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'mfa_enabled_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null;
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}
