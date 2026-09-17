<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The password, for the two acts in this feature that a session alone is not
 * enough for (ADR 0030).
 *
 * Turning the factor OFF makes the account weaker. Replacing the recovery codes
 * makes ten new ways in and kills ten old ones. Both are what somebody does
 * with a laptop left unlocked, so both ask for something the person would have
 * to KNOW rather than merely something they have.
 *
 * Turning the factor ON asks for nothing extra, because it cannot hurt the
 * owner of the account: the worst an intruder can do is lock themselves in
 * beside a password the owner can still change.
 */
final class PasswordConfirmationRequest extends FormRequest
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
