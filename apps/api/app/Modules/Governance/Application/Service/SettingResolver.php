<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hierarchical settings: organization → department → project → membership,
 * most specific wins (docs/03 §6).
 *
 * Cached per organization because settings are read on nearly every write path
 * (approvals check self-review, work items check the default estimate) and
 * change perhaps monthly. Invalidation is explicit on write rather than by TTL,
 * so turning a policy off takes effect immediately — a security-adjacent
 * setting that lags five minutes behind is a setting nobody trusts.
 */
final class SettingResolver
{
    private const TTL_SECONDS = 3600;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{scope_type: string, scope_id: string}|null  $scope
     */
    public function get(string $key, mixed $default = null, ?array $scope = null): mixed
    {
        $memoKey = $key.'|'.($scope['scope_type'] ?? 'org').'|'.($scope['scope_id'] ?? '');

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $all = $this->organizationSettings();

        // Most specific first, falling back to the organization default.
        $value = $scope !== null
            ? ($all[$scope['scope_type'].':'.$scope['scope_id'].':'.$key] ?? $all['organization::'.$key] ?? $default)
            : ($all['organization::'.$key] ?? $default);

        return $this->memo[$memoKey] = $value;
    }

    public function forget(): void
    {
        $this->memo = [];
        Cache::forget($this->cacheKey());
    }

    /** @return array<string, mixed> */
    private function organizationSettings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = Cache::remember($this->cacheKey(), self::TTL_SECONDS, function (): array {
            $rows = DB::table('settings')
                ->where('organization_id', $this->tenant->organizationId())
                ->get(['scope_type', 'scope_id', 'key', 'value']);

            $map = [];

            foreach ($rows as $row) {
                $map[$row->scope_type.':'.($row->scope_id ?? '').':'.$row->key]
                    = json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR);
            }

            return $map;
        });

        return $settings;
    }

    private function cacheKey(): string
    {
        return 'settings:'.$this->tenant->organizationId();
    }
}
