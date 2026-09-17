<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning the second factor off, with the password in hand (ADR 0030).
 *
 * A session is not enough here and is the only place in this feature where that
 * is true. Removing a factor is the one act that makes the account weaker, and
 * it is what somebody does with a laptop left unlocked — so it asks for
 * something they would have to KNOW, not merely something they have.
 */
final class DisableMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
