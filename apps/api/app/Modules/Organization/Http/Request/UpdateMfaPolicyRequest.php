<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requiring a second factor of everybody in this organization (ADR 0033).
 *
 * One boolean, and `boolean` rather than `accepted`: this field is set to false
 * as often as to true, and a rule that only validates the "on" direction is a
 * rule that lets `"no"` through as truthy on the way back off.
 */
final class UpdateMfaPolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'require_mfa' => ['required', 'boolean'],
        ];
    }

    public function required(): bool
    {
        return $this->boolean('require_mfa');
    }
}
