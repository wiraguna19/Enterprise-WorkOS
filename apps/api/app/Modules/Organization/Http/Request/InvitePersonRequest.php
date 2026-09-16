<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An invitation, at the door.
 *
 * The role is optional and that is deliberate: somebody can be invited into the
 * organization before anyone has decided what they will do, and the roles
 * screen is where that decision belongs. An invitation that REQUIRED a role
 * would push a permanent choice into the most hurried moment.
 */
final class InvitePersonRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
