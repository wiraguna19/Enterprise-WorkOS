<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resource;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Organization\Infrastructure\Eloquent\EmployeeProfileModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * A person, as seen inside one organization.
 *
 * Note what is NOT here: the user's other organizations, their global account
 * state, their MFA status. A membership resource exposes the tenant-local view
 * of a human and nothing that belongs to their identity elsewhere.
 *
 * One resource serves both the directory and the profile, but the profile
 * fields are opt-in through detail() rather than inferred from whatever the
 * caller happened to eager load. Inferring would tie the shape of the response
 * to an optimisation: someone adding `with('roles')` to the list query for
 * speed would silently change the API contract for every directory row.
 *
 * The directory shows what you compare people BY; the profile adds what you
 * look one person UP for.
 *
 * @property MembershipModel $resource
 */
final class PersonResource extends BaseResource
{
    private bool $detailed = false;

    /** The profile view: reporting line, roles, and employment detail. */
    public static function detail(MembershipModel $membership): self
    {
        $resource = new self($membership);
        $resource->detailed = true;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $profile = $this->resource->employeeProfile;

        $permissions = $this->permissions([
            'update' => 'update',
            'deactivate' => 'deactivate',
            'view_workload' => 'viewWorkload',
        ]);

        return [
            'id' => $this->resource->id,
            'type' => 'person',
            'name' => $this->resource->user?->name,
            'email' => $this->resource->user?->email,
            'avatar_url' => $this->resource->user?->avatar_path,
            'status' => $this->resource->status,
            'joined_at' => $this->resource->joined_at,
            'job_title' => $profile?->job_title,
            'employment_type' => $profile?->employment_type,
            'weekly_capacity_hours' => $profile?->weekly_capacity_hours,
            'department' => $profile?->department === null ? null : [
                'id' => $profile->department->id,
                'name' => $profile->department->name,
            ],
            'permissions' => $permissions,
        ] + ($this->detailed ? $this->profileFields($profile, $permissions) : []);
    }

    /**
     * @param  array<string, bool>  $permissions
     * @return array<string, mixed>
     */
    private function profileFields(?EmployeeProfileModel $profile, array $permissions): array
    {
        $manager = $profile?->manager;

        $fields = [
            'roles' => $this->resource->roles
                ->map(static fn (RoleModel $role): array => [
                    'id' => $role->id,
                    'key' => $role->key,
                    'name' => $role->name,
                ])
                ->values()
                ->all(),

            // The manager is identified by their MEMBERSHIP id, not their
            // profile id: that is what /people/{id} takes, so a client can link
            // straight there without a second lookup to translate the two.
            'manager' => $manager?->membership === null ? null : [
                'id' => $manager->membership->id,
                'name' => $manager->membership->user?->name,
                'job_title' => $manager->job_title,
            ],

            'direct_reports' => $profile === null ? [] : $profile->directReports
                ->filter(static fn (EmployeeProfileModel $report): bool => $report->membership !== null)
                ->map(static fn (EmployeeProfileModel $report): array => [
                    'id' => $report->membership?->id,
                    'name' => $report->membership?->user?->name,
                    'job_title' => $report->job_title,
                ])
                ->values()
                ->all(),

            'work_location' => $profile?->work_location,
            'hired_at' => $profile?->hired_at,
        ];

        // HR bookkeeping, not directory information. It goes to the people who
        // can edit the record — which includes the person themselves — and to
        // nobody else, so a payroll number does not travel to every colleague
        // who opens a profile (docs/06 §2).
        if ($permissions['update']) {
            $fields['employee_number'] = $profile?->employee_number;
        }

        return $fields;
    }
}
