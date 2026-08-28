<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class LogTimeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 0.25 steps are not enforced: people work in the increments they
            // work in, and rounding someone's 20 minutes up to half an hour is
            // a lie the report cannot detect.
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],

            // Future dates are refused. Logging tomorrow's work is either a
            // typo or a plan, and neither belongs in a record of what was
            // actually spent.
            'logged_on' => ['sometimes', 'date', 'before_or_equal:today'],

            'note' => ['sometimes', 'nullable', 'string', 'max:300'],

            // Logging on someone else's behalf is a separate ability, checked
            // in the controller — a manager filling in a timesheet for someone
            // on leave is real, and so is the audit question it raises.
            'membership_id' => ['sometimes', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logged_on.before_or_equal' => 'Time can only be logged for today or earlier.',
            'hours.gt' => 'Log more than zero hours.',
        ];
    }
}
