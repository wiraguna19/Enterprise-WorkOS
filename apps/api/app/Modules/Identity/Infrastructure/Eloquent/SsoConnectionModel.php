<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Saml\IdentityProvider;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The identity provider an organization signs its people in through (ADR 0052).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $idp_entity_id
 * @property string $idp_sso_url
 * @property string $idp_certificate
 * @property bool $enforced
 * @property CarbonImmutable|null $last_succeeded_at
 * @property string|null $created_by_membership_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SsoConnectionModel extends TenantModel
{
    protected $table = 'sso_connections';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enforced' => 'boolean',
            'last_succeeded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<SsoDomainModel, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(SsoDomainModel::class, 'connection_id');
    }

    /** The three facts the SAML toolkit needs, and nothing tenant-shaped. */
    public function identityProvider(): IdentityProvider
    {
        return new IdentityProvider(
            entityId: $this->idp_entity_id,
            ssoUrl: $this->idp_sso_url,
            certificate: $this->idp_certificate,
        );
    }
}
