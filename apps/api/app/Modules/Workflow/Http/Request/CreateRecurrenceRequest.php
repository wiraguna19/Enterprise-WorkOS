<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use App\Modules\Work\Application\Service\WorkItemService;
use App\Modules\Work\Domain\Exception\NoWorkflowForType;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RRule\RRule;
use Throwable;

/**
 * A recurrence is validated twice on purpose: the shape here, and the RULE by
 * the library that will later expand it.
 *
 * Accepting an RRULE this codebase cannot parse would store a rule that fails
 * for the first time on the scheduler at 03:00, where the person who wrote it
 * is not watching. It is parsed at the door instead (docs/03 §4).
 */
final class CreateRecurrenceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rrule' => ['required', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:today'],

            'template' => ['required', 'array'],
            'template.title' => ['required', 'string', 'max:500'],
            // The model's own lists, not a copy. This request kept a private
            // TYPES that happened to match; two lists that must agree
            // eventually will not (ADR 0047).
            'template.type' => ['sometimes', 'string', Rule::in(WorkItemModel::TYPES)],
            'template.project_id' => ['sometimes', 'nullable', 'uuid'],
            'template.priority' => ['sometimes', 'string', Rule::in(WorkItemModel::PRIORITIES)],
            'template.description' => ['sometimes', 'string', 'max:10000'],
            'template.estimate_hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'],

            // Relative, never absolute: "due three days after it appears" is
            // what a recurring task means. An absolute date in a template would
            // be the same date forever.
            'template.due_in_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'template.assignee_id' => ['sometimes', 'nullable', 'uuid'],

            // The organization's own fields (ADR 0038), carried from a work
            // item template when the recurrence was started from one (ADR
            // 0047). Only the shape here; which keys exist and what each
            // accepts is decided by the field definitions when each occurrence
            // is created, as for any other work item.
            'template.custom_fields' => ['sometimes', 'array'],
            'template.custom_fields.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnroutableType($validator);

            $rrule = $this->string('rrule')->toString();

            if ($rrule === '' || $validator->errors()->has('rrule')) {
                return;
            }

            try {
                $rule = new RRule($rrule, $this->startsAt());
            } catch (Throwable $e) {
                $validator->errors()->add(
                    'rrule',
                    'That is not a recurrence rule this system can read: '.$e->getMessage(),
                );

                return;
            }

            // A rule with no occurrence ahead of it is not a recurrence; it is a
            // work item somebody should just create.
            if ($this->firstOccurrenceAfterNow($rule) === null) {
                $validator->errors()->add('rrule', 'That rule has no future occurrences.');
            }
        });
    }

    /**
     * A type the schema allows is not a type the organization can route.
     *
     * Refused at the door, because the alternative is worse than a 422: the
     * rule would be stored, and the first time it fired the materializer would
     * fail to find a workflow and switch the recurrence off with the reason in
     * a log line — at 03:00, where the person who set it up is not watching.
     * The same sentence the create endpoint gives (ADR 0047).
     */
    private function refuseUnroutableType(Validator $validator): void
    {
        $type = $this->input('template.type');

        if (! is_string($type) || $validator->errors()->has('template.type')) {
            return;
        }

        if (! in_array($type, app(WorkItemService::class)->creatableTypes(), strict: true)) {
            // The exception's sentence, not a second copy of it.
            $validator->errors()->add('template.type', NoWorkflowForType::for($type)->getMessage());
        }
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->filled('starts_at')
            ? new DateTimeImmutable($this->string('starts_at')->toString())
            : new DateTimeImmutable;
    }

    /**
     * RRule is itself iterable over the occurrences it generates, so the type
     * has to say what it yields.
     *
     * @param  RRule<DateTimeInterface>|null  $rule
     */
    public function firstOccurrenceAfterNow(?RRule $rule = null): ?DateTimeImmutable
    {
        $rule ??= new RRule($this->string('rrule')->toString(), $this->startsAt());
        $now = new DateTimeImmutable;

        foreach ($rule as $occurrence) {
            $at = DateTimeImmutable::createFromInterface($occurrence);

            if ($at > $now) {
                return $at;
            }
        }

        return null;
    }
}
