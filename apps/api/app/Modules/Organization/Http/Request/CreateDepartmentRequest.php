<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Request;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware has already checked department.create; per-record
        // authorization does not apply to a record that does not exist yet.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/',
                // Uniqueness is scoped to the tenant. Without the where() this
                // rule would leak the existence of another organization's codes.
                Rule::unique('departments', 'code')
                    ->where('organization_id', app(TenantContext::class)->organizationId()),
            ],
            'parent_id' => ['nullable', 'uuid', Rule::exists('departments', 'id')
                ->where('organization_id', app(TenantContext::class)->organizationId())],
        ];
    }
}
