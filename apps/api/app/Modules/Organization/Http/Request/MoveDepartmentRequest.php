<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

final class MoveDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Cycle and depth checks are NOT validation rules: they are domain
        // invariants that need row locks to be correct under concurrency, so
        // they live in DepartmentService inside the transaction.
        return [
            'parent_id' => ['present', 'nullable', 'uuid'],
        ];
    }
}
