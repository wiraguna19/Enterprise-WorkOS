<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Request;

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH semantics: only what changed is sent, which is also what makes the
 * activity-log diff meaningful (docs/05 §5).
 *
 * Status is deliberately NOT updatable here — it moves through /transition,
 * because a status change has rules and side effects that a field edit does not.
 */
final class UpdateWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:2', 'max:500'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(WorkItemModel::PRIORITIES)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimate_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'milestone_id' => ['sometimes', 'nullable', 'uuid'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
