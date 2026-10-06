<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * An email domain whose addresses sign in through one organization's IdP
 * (ADR 0052). Pending until a DNS TXT record proves the organization owns it;
 * unique across every organization once proven — one address, one IdP.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $connection_id
 * @property string $domain
 * @property string $verification_token
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 */
final class SsoDomainModel extends TenantModel
{
    protected $table = 'sso_domains';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
