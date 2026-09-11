<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A legal move, at the door.
 *
 * `from_state_id` is nullable and that null is meaningful: it is "from
 * anywhere", which is how Blocked and Cancelled are reachable without a dozen
 * near-identical rows. `nullable` rather than `sometimes` so the difference
 * between "not sent" and "deliberately from anywhere" survives the request.
 */
final class CreateTransitionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_state_id' => ['present', 'nullable', 'uuid'],
            'to_state_id' => ['required', 'uuid', 'different:from_state_id'],
            'label' => ['required', 'string', 'max:60'],
            'requires_comment' => ['sometimes', 'boolean'],
        ];
    }
}
