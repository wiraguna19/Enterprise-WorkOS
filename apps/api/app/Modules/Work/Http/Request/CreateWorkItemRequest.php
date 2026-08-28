<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Request;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'title' => ['required', 'string', 'min:2', 'max:500'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'type' => ['sometimes', Rule::in(WorkItemModel::TYPES)],
            // Nullable and that is deliberate: project-less work is a
            // first-class case (docs/02 §5).
            'project_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId)],
            'parent_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('work_items', 'id')->where('organization_id', $organizationId)],
            'milestone_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('milestones', 'id')->where('organization_id', $organizationId)],
            'priority' => ['sometimes', Rule::in(WorkItemModel::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'estimate_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            // Assignment happens in the create step. A separate assign action
            // afterwards is the single most common unnecessary click in tools
            // of this kind (docs/08 §4).
            'assignee_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('memberships', 'id')->where('organization_id', $organizationId)],
            'reviewer_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('memberships', 'id')->where('organization_id', $organizationId)],
        ];
    }
}
