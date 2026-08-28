<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Infrastructure\Eloquent;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * A subscription URL, stored as a digest (docs/06 §1).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $token_hash
 * @property CarbonImmutable|null $last_accessed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CalendarFeedModel extends TenantModel
{
    protected $table = 'calendar_feeds';

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_accessed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Look a feed up by the token in the URL.
     *
     * The lookup cannot be tenant-scoped: the request arrives from a calendar
     * client with no session and no organization, so the digest is the only
     * thing there is to find it by — and the row it finds is what establishes
     * the tenant, exactly as a session does at login.
     *
     * It crosses tenants through runAsPlatform() rather than dropping the
     * scope directly, so the crossing is logged like every other one. A bare
     * withoutGlobalScopes() here would be an unlogged, ungreppable hole in the
     * isolation boundary reachable by an unauthenticated URL — the worst place
     * to have one.
     */
    public static function forToken(string $token): ?self
    {
        $digest = hash('sha256', $token);

        return app(TenantContext::class)->runAsPlatform(
            'calendar.feed_token_lookup',
            static fn (): ?self => self::query()->where('token_hash', $digest)->first(),
        );
    }
}
