<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use App\Modules\Platform\Domain\Work\StateCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A state, at the door.
 *
 * The category list is closed and comes from Platform, because it is the piece
 * every list, board and report reasons about — a customer names the state, the
 * product counts the category (docs/02 §7).
 *
 * `key` is accepted on create and refused on edit, and the refusal is the
 * editor's rather than the validator's: it is a rule about what changing a key
 * would DO to existing rules, not about the shape of the request.
 */
final class SaveStateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'key' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:40',
                // Lowercase and underscores: this is the token rules match on,
                // not a name anybody reads.
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'category' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'in:'.implode(',', StateCategory::ALL),
            ],
            'color' => ['sometimes', 'string', 'max:20'],
            'requires_approval' => ['sometimes', 'boolean'],
        ];
    }
}
