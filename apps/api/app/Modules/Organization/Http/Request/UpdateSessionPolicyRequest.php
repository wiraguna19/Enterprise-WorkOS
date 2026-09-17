<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing how long a session may live here (ADR 0028).
 *
 * The bounds repeat the CHECK constraint on the column on purpose. They are not
 * the enforcement — the database is, because a form request protects one door
 * and this value is also written by seeders, by tinker, and by whatever
 * provisioning grows later. What the request adds is a readable refusal instead
 * of a 500 from Postgres.
 */
final class UpdateSessionPolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_lifetime_days' => ['required', 'integer', 'min:1', 'max:90'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_lifetime_days.min' => 'A session must last at least a day. Zero is a lockout, not a policy.',
            'session_lifetime_days.max' => 'Ninety days is the longest a session may live.',
        ];
    }

    public function days(): int
    {
        return (int) $this->input('session_lifetime_days');
    }
}
