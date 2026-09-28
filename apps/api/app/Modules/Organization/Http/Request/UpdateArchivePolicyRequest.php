<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * How long closed work stays in view here (ADR 0054).
 *
 * Required and nullable, like the idle window: null is a real answer —
 * "never archive" — and a PATCH that treated a missing key as null would
 * switch archiving off every time somebody saved an unrelated form.
 *
 * The bounds repeat the CHECK on the column, for a readable refusal instead of
 * a 500 from Postgres.
 */
final class UpdateArchivePolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'archive_closed_after_days' => ['present', 'nullable', 'integer', 'min:7', 'max:3650'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'archive_closed_after_days.min' => 'A week is the shortest: closed work a board no longer shows should at least have been seen there.',
            'archive_closed_after_days.max' => 'Ten years is the longest worth setting. Use "never" instead.',
        ];
    }

    public function days(): ?int
    {
        $value = $this->input('archive_closed_after_days');

        return is_numeric($value) ? (int) $value : null;
    }
}
