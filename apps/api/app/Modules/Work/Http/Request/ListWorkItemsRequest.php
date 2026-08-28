<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Request;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One filter grammar for every collection endpoint (docs/05 §4).
 *
 * Allowed filters and sorts are a WHITELIST. An unknown key is a 422, never
 * silently ignored — silent ignoring is how a client ships a broken filter that
 * nobody notices for a month.
 */
final class ListWorkItemsRequest extends FormRequest
{
    private const SORTABLE = [
        'due_at', 'created_at', 'updated_at', 'priority', 'position', 'reference',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.project_id' => ['sometimes', 'uuid'],
            'filter.milestone_id' => ['sometimes', 'uuid'],
            'filter.parent_id' => ['sometimes', 'nullable', 'uuid'],
            'filter.type' => ['sometimes', 'string', Rule::in(WorkItemModel::TYPES)],
            'filter.priority' => ['sometimes', 'string'],
            'filter.state_category' => ['sometimes', 'string'],
            'filter.assignee_id' => ['sometimes', 'string'],
            'filter.team_id' => ['sometimes', 'uuid'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'filter.unassigned' => ['sometimes', 'boolean'],
            'filter.tag' => ['sometimes', 'string', 'max:60'],
            'q' => ['sometimes', 'string', 'min:2', 'max:200'],
            'sort' => ['sometimes', 'string', 'max:80'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filter.type.in' => 'Unknown work item type. Allowed: '.implode(', ', WorkItemModel::TYPES).'.',
        ];
    }

    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function applyFilters(Builder $query): Builder
    {
        $filter = (array) $this->input('filter', []);

        if (isset($filter['project_id'])) {
            $query->where('project_id', $filter['project_id']);
        }

        if (isset($filter['milestone_id'])) {
            $query->where('milestone_id', $filter['milestone_id']);
        }

        // Explicit null means "top level only" — distinct from the key being
        // absent, which means "no opinion".
        if ($this->has('filter.parent_id')) {
            $parent = $filter['parent_id'] ?? null;
            $parent === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parent);
        }

        if (isset($filter['type'])) {
            $query->where('type', $filter['type']);
        }

        // Comma-separated multi-value, validated against the closed category
        // list so an unknown value cannot silently match nothing.
        if (isset($filter['state_category'])) {
            $categories = array_intersect(
                explode(',', (string) $filter['state_category']),
                StateCategory::ALL,
            );

            $query->whereIn('state_category', $categories ?: ['__none__']);
        }

        if (isset($filter['priority'])) {
            $query->whereIn('priority', array_intersect(
                explode(',', (string) $filter['priority']),
                WorkItemModel::PRIORITIES,
            ) ?: ['__none__']);
        }

        if (isset($filter['assignee_id'])) {
            // `me` resolves server-side; the client never needs to know its own
            // membership id to ask this question (docs/05 §4).
            $membershipId = $filter['assignee_id'] === 'me'
                ? app(TenantContext::class)->membershipId()
                : (string) $filter['assignee_id'];

            $query->assignedTo($membershipId);
        }

        if (isset($filter['team_id'])) {
            // "The team's work" is work assigned to someone currently on the
            // team — teams do not own work items, people do (docs/03 §2). Both
            // sides are filtered to the present: a member who left does not
            // take their old assignments off the board, and an assignment that
            // ended does not keep the item on it.
            //
            // Not tenant-filtered here, and it does not need to be: the outer
            // query is already scoped, and the assignment rows it joins through
            // belong to work items in this organization.
            $query->whereExists(fn ($sub) => $sub
                ->from('work_item_assignments as wia')
                ->join('team_members as tm', 'tm.membership_id', '=', 'wia.membership_id')
                ->whereColumn('wia.work_item_id', 'work_items.id')
                ->whereNull('wia.unassigned_at')
                ->whereNull('tm.left_at')
                ->where('tm.team_id', $filter['team_id']));
        }

        if ($this->boolean('filter.overdue')) {
            $query->overdue();
        }

        if ($this->boolean('filter.unassigned')) {
            $query->whereNotExists(fn ($sub) => $sub
                ->from('work_item_assignments')
                ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
                ->where('role', 'assignee')
                ->whereNull('unassigned_at'));
        }

        if (isset($filter['tag'])) {
            $query->whereExists(fn ($sub) => $sub
                ->from('taggables')
                ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                ->whereColumn('taggables.taggable_id', 'work_items.id')
                ->where('taggables.taggable_type', 'work_item')
                ->whereRaw('lower(tags.name) = ?', [mb_strtolower((string) $filter['tag'])]));
        }

        if ($this->filled('q')) {
            $query->matching($this->string('q')->toString());

            return $query;   // relevance ordering wins; an explicit sort would fight it
        }

        return $this->applySort($query);
    }

    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    private function applySort(Builder $query): Builder
    {
        $sort = $this->string('sort')->toString() ?: 'position';

        foreach (explode(',', $sort) as $field) {
            $descending = str_starts_with($field, '-');
            $column = ltrim($field, '-');

            if (! in_array($column, self::SORTABLE, strict: true)) {
                continue;
            }

            // NULL due dates sort last regardless of direction: work with no
            // deadline is not more urgent than work due tomorrow.
            $column === 'due_at'
                ? $query->orderByRaw('due_at IS NULL, due_at '.($descending ? 'desc' : 'asc'))
                : $query->orderBy($column, $descending ? 'desc' : 'asc');
        }

        // Cursor pagination needs a total order; without a unique tiebreaker the
        // same row can appear on two pages.
        return $query->orderBy('id');
    }
}
