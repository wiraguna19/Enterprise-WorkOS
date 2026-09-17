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

            // Optional, and nullable on purpose: null is a real answer here —
            // "no idle timeout" — so absent and null must mean different
            // things. Absent leaves the setting alone; null switches it off.
            'idle_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:10080'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_lifetime_days.min' => 'A session must last at least a day. Zero is a lockout, not a policy.',
            'session_lifetime_days.max' => 'Ninety days is the longest a session may live.',
            'idle_timeout_minutes.min' => 'Five minutes is the shortest idle window. Below that the product signs people out while they read.',
            'idle_timeout_minutes.max' => 'Seven days is the longest idle window worth setting.',
        ];
    }

    public function days(): int
    {
        return (int) $this->input('session_lifetime_days');
    }

    /** True when the caller said something about the idle window at all. */
    public function touchesIdleWindow(): bool
    {
        return $this->has('idle_timeout_minutes');
    }

    public function idleMinutes(): ?int
    {
        $value = $this->input('idle_timeout_minutes');

        return is_numeric($value) ? (int) $value : null;
    }
}
