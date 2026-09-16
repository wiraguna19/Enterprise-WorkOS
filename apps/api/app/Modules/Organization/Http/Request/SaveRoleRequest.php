<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A role a customer is writing, at the door.
 *
 * The permission list is validated as SHAPE here and as authority in the
 * builder: whether each key exists, and whether the author holds it, are
 * questions about the catalogue and about the actor — neither of which belongs
 * in a request object (ADR 0018).
 *
 * `permissions` is `present` on create and `sometimes` on edit, and the
 * difference matters: an edit that omits it leaves the role's permissions
 * alone, while an edit that sends `[]` empties them. A form that could not say
 * "no change" would rewrite the set on every rename.
 */
final class SaveRoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            // Lowercase and underscores: a role key is a token that appears in
            // grants and audit logs, not a name anybody reads.
            'key' => [$creating ? 'required' : 'prohibited', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => [$creating ? 'sometimes' : 'sometimes', 'string', 'max:255'],
            'permissions' => [$creating ? 'present' : 'sometimes', 'array'],
            'permissions.*' => ['string', 'max:80'],
        ];
    }

    /**
     * The permission set, or null for "leave it alone".
     *
     * @return list<string>|null
     */
    public function permissionKeys(): ?array
    {
        if (! $this->has('permissions')) {
            return null;
        }

        return array_values(array_map(strval(...), $this->array('permissions')));
    }
}
