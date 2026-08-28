<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\Concerns\BelongsToOrganization;

/**
 * Base class for every tenant-scoped persistence model.
 *
 * Extending this (rather than BaseModel) is the only thing a developer must
 * remember. The isolation test suite verifies that every model with an
 * organization_id column does extend it.
 */
abstract class TenantModel extends BaseModel
{
    use BelongsToOrganization;
}
