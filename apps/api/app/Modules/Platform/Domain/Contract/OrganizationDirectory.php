<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contract;

/**
 * The little that other modules may know about an organization.
 *
 * Identity needs an organization's name for the session payload, and nothing
 * else. Importing Organization's model to get it would point the module graph
 * backwards — people belong to organizations, so Organization depends on
 * Identity and never the reverse (docs/04 §3).
 *
 * The interface lives in Platform, which everyone may depend on; Organization
 * implements it and binds it. Deliberately narrow: it is a read of three
 * fields, not a door into another module's data.
 */
interface OrganizationDirectory
{
    /**
     * @return array{id: string, name: string, slug: string}|null
     */
    public function summary(string $organizationId): ?array;
}
