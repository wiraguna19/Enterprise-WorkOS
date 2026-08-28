<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'head_membership_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
