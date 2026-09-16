<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use App\Modules\Identity\Application\Service\RoleAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A scoped grant, at the door.
 *
 * The scope is REQUIRED, and that is the design rather than an omission: an
 * organization-wide grant is how somebody becomes an administrator of
 * everything, and it should not be reachable by leaving a dropdown alone.
 *
 * The role key itself is checked against this organization's roles by the
 * service, not here — roles are rows, and a customer's own role is as real as a
 * system one.
 */
final class GrantRoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:40'],
            'scope_type' => ['required', 'string', 'in:'.implode(',', RoleAssignment::SCOPES)],
            'scope_id' => ['required', 'uuid'],
        ];
    }
}
