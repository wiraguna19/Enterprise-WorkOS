<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use App\Modules\Identity\Application\Service\RoleAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking one permission away from one person, at the door (ADR 0020).
 *
 * Two shapes differ from the grant beside it, and both are deliberate:
 *
 * - The scope is OPTIONAL here, where a grant requires one. A grant with no
 *   scope makes somebody an administrator of everything by leaving a dropdown
 *   alone; a denial with no scope takes something away everywhere, which is the
 *   safe direction to reach by accident.
 * - The reason is REQUIRED, and is the only free text in this module that is.
 *   A denial outlives the incident that caused it, and an entry with no reason
 *   is a mystery to whoever reads it six months later — including the person
 *   who wrote it.
 */
final class DenyPermissionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', 'max:80'],
            // Scoped or not, but never half: a type with no id names nothing,
            // and an id with no type says where without saying what.
            'scope_type' => ['nullable', 'required_with:scope_id', 'string', 'in:'.implode(',', RoleAssignment::SCOPES)],
            'scope_id' => ['nullable', 'required_with:scope_type', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function scopeType(): ?string
    {
        $value = $this->input('scope_type');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function scopeId(): ?string
    {
        $value = $this->input('scope_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
