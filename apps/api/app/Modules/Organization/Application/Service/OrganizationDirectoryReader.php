<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Organization\Infrastructure\Eloquent\OrganizationModel;
use App\Modules\Platform\Domain\Contract\OrganizationDirectory;

/**
 * Organization's own answer to "what is this organization called".
 *
 * Reads through the model rather than the table so the tenant scope, soft
 * deletes, and casts all apply exactly as they do everywhere else in this
 * module.
 */
final class OrganizationDirectoryReader implements OrganizationDirectory
{
    /** {@inheritDoc} */
    public function summary(string $organizationId): ?array
    {
        /** @var OrganizationModel|null $organization */
        $organization = OrganizationModel::query()->find($organizationId);

        if ($organization === null) {
            return null;
        }

        return [
            'id' => (string) $organization->id,
            'name' => (string) $organization->name,
            'slug' => (string) $organization->slug,
        ];
    }
}
