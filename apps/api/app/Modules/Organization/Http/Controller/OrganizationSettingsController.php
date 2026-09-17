<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\SessionLifetime;
use App\Modules\Organization\Http\Request\UpdateSessionPolicyRequest;
use App\Modules\Organization\Infrastructure\Eloquent\OrganizationModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;

/**
 * The organization's own settings (ADR 0028).
 *
 * The first endpoint in the product that `organization.view` and
 * `organization.manage_settings` gate. Both keys have been in the catalogue
 * since Phase 1, ticked in the role builder, granted to two roles — and
 * consulted by nothing on the server, while the web app hid its Settings entry
 * behind the first of them. An interface that hides a control on a permission
 * the API has never heard of is a product that LOOKS like it enforces
 * something; `EveryPermissionMeansSomethingTest` says so in those words.
 *
 * Read and write are separated because they are different acts: seeing that
 * sessions here last seven days is part of understanding the place you work,
 * and changing it decides when everybody is signed out.
 */
final class OrganizationSettingsController extends ApiController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SessionLifetime $lifetime,
    ) {}

    public function show(): ApiResponse
    {
        $organization = $this->current();

        return $this->ok([
            'id' => (string) $organization->id,
            'name' => (string) $organization->name,
            'slug' => (string) $organization->slug,
            'session_lifetime_days' => (int) $organization->session_lifetime_days,
        ]);
    }

    /**
     * Set the window, and apply it to what is already open.
     *
     * The clamp is inside the same request rather than left to a scheduled job:
     * an administrator shortening the window because a device went missing
     * needs it to have happened by the time the page comes back, not by the
     * time cron next runs.
     */
    public function updateSessionPolicy(UpdateSessionPolicyRequest $request): ApiResponse
    {
        $organization = $this->current();
        $days = $request->days();

        $organization->forceFill(['session_lifetime_days' => $days])->save();

        $shortened = $this->lifetime->clampTo(
            (string) $organization->id,
            $days,
            $request,
        );

        return $this->ok([
            'session_lifetime_days' => $days,
            // Reported, not hidden. This number is the difference between a
            // setting and a consequence, and the person who pressed the button
            // is the one who should learn it first.
            'sessions_shortened' => $shortened,
        ]);
    }

    private function current(): OrganizationModel
    {
        /** @var OrganizationModel $organization */
        $organization = OrganizationModel::query()
            ->findOrFail($this->tenant->organizationId());

        return $organization;
    }
}
