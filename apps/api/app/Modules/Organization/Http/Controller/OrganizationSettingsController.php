<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\RecentAuthentication;
use App\Modules\Identity\Application\Service\SessionLifetime;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Application\Service\OrganizationSettingsService;
use App\Modules\Organization\Http\Request\UpdateMfaPolicyRequest;
use App\Modules\Organization\Http\Request\UpdateSessionPolicyRequest;
use App\Modules\Organization\Infrastructure\Eloquent\OrganizationModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Database\Eloquent\Builder;

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
        private readonly RecentAuthentication $recent,
        private readonly OrganizationSettingsService $settings,
    ) {}

    public function show(): ApiResponse
    {
        $organization = $this->current();

        return $this->ok([
            'id' => (string) $organization->id,
            'name' => (string) $organization->name,
            'slug' => (string) $organization->slug,
            'session_lifetime_days' => (int) $organization->session_lifetime_days,
            'idle_timeout_minutes' => $organization->idle_timeout_minutes,
            'require_mfa' => $organization->require_mfa,
            // How many people the policy would confine if it were switched on
            // right now — or is confining, if it already is. The number is the
            // difference between a setting and a consequence (ADR 0028), and
            // here the consequence lands on other people.
            'people_without_mfa' => $this->peopleWithoutSecondFactor(),
        ]);
    }

    /**
     * Require a second factor of everybody here (ADR 0033).
     *
     * Nobody is signed out and nobody is locked out: a person without a factor
     * keeps their session and can do four things with it — say who they are,
     * sign out, start enrolment, finish it. Everything else answers 403 until
     * they do.
     */
    public function updateMfaPolicy(UpdateMfaPolicyRequest $request): ApiResponse
    {
        // Changes what everybody in the organization must do before their next
        // request, so it asks who is asking (ADR 0034).
        $this->recent->require($request);

        $organization = $this->current();

        $this->settings->setMfaRequired($organization, $request->required());

        return $this->ok([
            'require_mfa' => $organization->require_mfa,
            'people_confined' => $request->required() ? $this->peopleWithoutSecondFactor() : 0,
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
        // Shortening the window signs devices out early; lengthening it keeps
        // them alive for longer. Both are the kind of change somebody would
        // make quietly from a borrowed screen (ADR 0034).
        $this->recent->require($request);

        $organization = $this->current();
        $days = $request->days();

        $changes = ['session_lifetime_days' => $days];

        // Absent leaves it alone; null switches it off. A PATCH that treated a
        // missing key as "off" would turn the idle timeout off every time
        // somebody changed the lifetime beside it.
        if ($request->touchesIdleWindow()) {
            $changes['idle_timeout_minutes'] = $request->idleMinutes();
        }

        $this->settings->setSessionPolicy($organization, $changes);

        $shortened = $this->lifetime->clampTo(
            (string) $organization->id,
            $days,
            $request,
        );

        return $this->ok([
            'session_lifetime_days' => $days,
            'idle_timeout_minutes' => $organization->idle_timeout_minutes,
            // Reported, not hidden. This number is the difference between a
            // setting and a consequence, and the person who pressed the button
            // is the one who should learn it first.
            'sessions_shortened' => $shortened,
        ]);
    }

    /** Active people here who would be asked to enrol before their next act. */
    private function peopleWithoutSecondFactor(): int
    {
        return MembershipModel::query()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereHas('user', fn (Builder $users) => $users->whereNull('mfa_enabled_at'))
            ->count();
    }

    private function current(): OrganizationModel
    {
        /** @var OrganizationModel $organization */
        $organization = OrganizationModel::query()
            ->findOrFail($this->tenant->organizationId());

        return $organization;
    }
}
