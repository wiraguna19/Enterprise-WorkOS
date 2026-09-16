<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting an invitation: who you are, and a password.
 *
 * Twelve characters, which is the only rule this product enforces: it is the
 * one place where a password is CHOSEN, so it is the only place that can refuse
 * a bad one.
 *
 * `uncompromised()` is deliberately NOT here. It calls the k-anonymity range
 * API from inside the request, so a breach check would put a network round trip
 * on the path of somebody creating their account and fail open when it timed
 * out — a check that is sometimes performed is a check nobody can rely on.
 * Making it dependable is a decision about where that call lives, not a rule to
 * add to a validator.
 */
final class AcceptInvitationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', Password::min(12)],
        ];
    }
}
