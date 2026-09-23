<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Request;

use App\Modules\Governance\Application\Service\CustomFields;
use App\Modules\Governance\Infrastructure\Eloquent\CustomFieldDefinitionModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One filter grammar for every collection endpoint (docs/05 §4).
 *
 * Allowed filters and sorts are a WHITELIST. An unknown key is a 422, never
 * silently ignored — silent ignoring is how a client ships a broken filter that
 * nobody notices for a month.
 *
 * **That paragraph was a claim and not a mechanism until now.** `rules()` named
 * every known key and nothing rejected the others, so `filter[assignee]=…` —
 * one letter short of `assignee_id` — returned the whole list, and
 * `sort=titel` sorted by position. Both are the failure the docblock describes,
 * and both were sitting under it. The whitelist below is the sentence made
 * true: `ALLOWED`, an explicit refusal, and a message that names the key.
 *
 * `cf_<key>` is part of the same grammar (docs/05 §4, ADR 0038). It is checked
 * against what THIS organization declared, which is the only place the answer
 * can come from, and an undeclared one is refused by name like any other
 * unknown key.
 */
final class ListWorkItemsRequest extends FormRequest
{
    private const SORTABLE = [
        'due_at', 'created_at', 'updated_at', 'priority', 'position', 'reference',
    ];

    /**
     * Every filter key this endpoint answers, apart from `cf_*`.
     *
     * A list, not the keys of `rules()`: a rule is `sometimes`, so a key with
     * no rule is not refused by validation — it is simply never mentioned. That
     * is exactly how the whitelist in the docblock above was absent for six
     * phases while appearing to be present.
     */
    private const ALLOWED = [
        'project_id', 'milestone_id', 'parent_id', 'type', 'priority',
        'state_category', 'state_id', 'assignee_id', 'team_id',
        'overdue', 'unassigned', 'tag',
    ];

    /** The prefix docs/05 §4 publishes for an organization's own fields. */
    private const CUSTOM_PREFIX = 'cf_';

    /**
     * The definition a `cf_*` key names, or null.
     *
     * Memoized per request: the key is validated and then applied, so without
     * this every custom filter costs two identical queries — and a list
     * endpoint is the one place where "twice" becomes "twice per page".
     *
     * @var array<string, CustomFieldDefinitionModel|null>
     */
    private array $customFields = [];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array', $this->onlyKnownFilters(...)],
            'filter.project_id' => ['sometimes', 'uuid'],
            'filter.milestone_id' => ['sometimes', 'uuid'],
            'filter.parent_id' => ['sometimes', 'nullable', 'uuid'],
            'filter.type' => ['sometimes', 'string', Rule::in(WorkItemModel::TYPES)],
            'filter.priority' => ['sometimes', 'string'],
            'filter.state_category' => ['sometimes', 'string'],
            // The STATE, not its category. A board column is one state and five
            // states can share a category, so filtering by category cannot
            // reproduce a column — which is what a truncated column's "see the
            // rest" needs (ADR 0012 §6).
            'filter.state_id' => ['sometimes', 'uuid'],
            'filter.assignee_id' => ['sometimes', 'string'],
            'filter.team_id' => ['sometimes', 'uuid'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'filter.unassigned' => ['sometimes', 'boolean'],
            'filter.tag' => ['sometimes', 'string', 'max:60'],
            'q' => ['sometimes', 'string', 'min:2', 'max:200'],
            'sort' => ['sometimes', 'string', 'max:80', $this->onlySortableFields(...)],
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
     * Refuse a filter key this endpoint does not answer.
     *
     * The message names the key and lists what is allowed, because the
     * commonest cause is a typo and the second commonest is a client written
     * against a different version. Neither is helped by "invalid filter".
     *
     * @param  mixed  $value
     */
    private function onlyKnownFilters(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $key) {
            $key = (string) $key;

            if (in_array($key, self::ALLOWED, strict: true)) {
                continue;
            }

            if (str_starts_with($key, self::CUSTOM_PREFIX)) {
                if (! is_scalar($value[$key])) {
                    // `?filter[cf_client][]=a&filter[cf_client][]=b` arrives as
                    // an array, and the apply step casts to string. Refused
                    // here rather than stringified into "Array", which would
                    // match nothing and look like an empty result.
                    $fail("Custom field \"{$key}\" takes one value.");

                    continue;
                }

                if ($this->customField($key) !== null) {
                    continue;
                }

                $fail("This organization has no custom field called \"{$key}\".");

                continue;
            }

            $fail("Unknown filter \"{$key}\". Allowed: ".implode(', ', self::ALLOWED).', or cf_<key> for a custom field.');
        }
    }

    /**
     * Refuse a sort this endpoint cannot perform.
     *
     * `applySort` used to `continue` past an unknown column, which is the same
     * silence the class docblock forbids one paragraph earlier: a client asking
     * for `sort=titel` got the default order and no sign that its request had
     * been dropped.
     *
     * @param  mixed  $value
     */
    private function onlySortableFields(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (explode(',', (string) $value) as $field) {
            $column = ltrim(trim($field), '-');

            if ($column === '' || in_array($column, self::SORTABLE, strict: true)) {
                continue;
            }

            $fail("Cannot sort by \"{$column}\". Allowed: ".implode(', ', self::SORTABLE).'.');
        }
    }

    private function customField(string $filterKey): ?CustomFieldDefinitionModel
    {
        $key = mb_substr($filterKey, mb_strlen(self::CUSTOM_PREFIX));

        if (! array_key_exists($key, $this->customFields)) {
            $this->customFields[$key] = app(CustomFields::class)->byKey('work_item', $key);
        }

        return $this->customFields[$key];
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

        if (isset($filter['state_id'])) {
            $query->where('workflow_state_id', $filter['state_id']);
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

        foreach ($filter as $key => $wanted) {
            if (! str_starts_with((string) $key, self::CUSTOM_PREFIX)) {
                continue;
            }

            $definition = $this->customField((string) $key);

            if ($definition === null) {
                // Unreachable: validation refused it. Kept because a guard that
                // depends on another layer having run is a guard that stops
                // holding the day somebody calls this method directly.
                continue;
            }

            $column = $definition->valueColumn();

            // Exact match, on the column the TYPE decides. A range grammar
            // (`[lte]`) is published for dates in docs/05 §4 and is not
            // implemented for anything yet, here included — better absent than
            // half-answered, because a range that silently becomes equality
            // returns a plausible, wrong list.
            $query->whereExists(fn ($sub) => $sub
                ->from('custom_field_values')
                ->whereColumn('custom_field_values.work_item_id', 'work_items.id')
                ->where('custom_field_values.definition_id', $definition->id)
                ->where('custom_field_values.'.$column, (string) $wanted));
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
                // Unreachable: `onlySortableFields` refused it. This used to be
                // the whole enforcement, and silently dropping the sort was the
                // bug — the request succeeded, in a different order than asked.
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
