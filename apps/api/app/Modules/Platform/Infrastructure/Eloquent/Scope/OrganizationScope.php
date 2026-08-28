<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Eloquent\Scope;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fails closed.
 *
 * If there is no tenant context, this throws rather than returning unscoped
 * rows. A missing scope must be a loud error in development and in tests, never
 * a quiet cross-tenant read in production.
 *
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformMode()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('organization_id'),
            '=',
            $context->organizationId() // throws TenantContextMissing when unbound
        );
    }
}
