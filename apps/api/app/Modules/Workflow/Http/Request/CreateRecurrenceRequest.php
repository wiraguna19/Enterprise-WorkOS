<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
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
    /** Types a recurrence may create — the same set work items allow. */
    private const TYPES = [
        'task', 'request', 'approval_work', 'incident', 'review', 'campaign', 'operational',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rrule' => ['required', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:today'],

            'template' => ['required', 'array'],
            'template.title' => ['required', 'string', 'max:500'],
            'template.type' => ['sometimes', 'string', 'in:'.implode(',', self::TYPES)],
            'template.project_id' => ['sometimes', 'nullable', 'uuid'],
            'template.priority' => ['sometimes', 'string', 'in:low,medium,high,urgent'],
            'template.description' => ['sometimes', 'string', 'max:10000'],
            'template.estimate_hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'],

            // Relative, never absolute: "due three days after it appears" is
            // what a recurring task means. An absolute date in a template would
            // be the same date forever.
            'template.due_in_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'template.assignee_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
