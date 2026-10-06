<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Infrastructure\Eloquent\EmployeeProfileModel;
use Illuminate\Http\Request;

/**
 * The facts of somebody's employment that other rules are computed from
 * (ADR 0063): hire date for tenure, level for the leave bonus, title, contract
 * type and weekly hours for capacity.
 *
 * Audited with what each value was before: these decide how much leave a
 * person is owed, and "who changed my hire date" has to have an answer.
 */
final class EmploymentRecords
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $values */
    public function update(MembershipModel $membership, array $values, ?Request $request = null): void
    {
        $profile = EmployeeProfileModel::query()->firstOrNew(['membership_id' => $membership->getKey()]);

        if (! $profile->exists) {
            $profile->id = EmployeeProfileModel::newId();
        }

        $before = $profile->only(array_keys($values));
        $profile->forceFill($values)->save();

        $this->audit->record('person.employment_updated', [
            'membership_id' => (string) $membership->getKey(),
            'changes' => array_keys($values),
            'before' => $before,
        ], $request, targetType: 'membership', targetId: (string) $membership->getKey());
    }
}
