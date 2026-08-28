<?php

declare(strict_types=1);

namespace App\Modules\Organization\Providers;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Application\Service\OrganizationDirectoryReader;
use App\Modules\Organization\Http\Policy\MembershipPolicy;
use App\Modules\Organization\Http\Policy\TeamPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\EmployeeProfileModel;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Domain\Contract\OrganizationDirectory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationDirectory::class, OrganizationDirectoryReader::class);

        // Scoped, for the same reason Work's policies are: Gate resolves a
        // policy from the container on every authorize() call, and this one
        // looks the actor up to read their permissions. A transient instance
        // would turn one lookup into three per directory row.
        $this->app->scoped(MembershipPolicy::class);
        $this->app->scoped(TeamPolicy::class);
    }

    public function boot(): void
    {
        /*
         * Organization contributes the employee-profile relation to Identity's
         * membership model.
         *
         * The profile is this module's, and Identity may not depend on this
         * module — the arrow runs Organization → Identity, because people
         * belong to organizations (docs/04 §3). Declaring the relation from
         * here keeps every `->with('employeeProfile')` working while the
         * dependency points the way the module graph says it must.
         */
        MembershipModel::resolveRelationUsing(
            'employeeProfile',
            fn (MembershipModel $membership) => $membership->hasOne(
                EmployeeProfileModel::class,
                'membership_id',
            ),
        );

        // Without this, every authorize() and every permissions{} block on a
        // person falls through to Gate's default deny: /people/{id} answers 403
        // to everyone, and the directory reports that nobody may edit anybody.
        // Laravel's convention-based discovery cannot find it — the modular
        // layout puts models and policies where the convention does not look.
        Gate::policy(MembershipModel::class, MembershipPolicy::class);
        Gate::policy(TeamModel::class, TeamPolicy::class);

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
