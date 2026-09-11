<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use App\Modules\Workflow\Application\Service\ActionExecutor;
use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A rule is validated at the door against the vocabulary that will run it.
 *
 * The evaluator is TOTAL — every malformed predicate is false rather than an
 * exception — which is right for a queued job and wrong for a form: a rule
 * saved with a misspelt field would simply never match, and "it never fires
 * and never errors" is the least debuggable outcome this product can produce.
 * So the door is where a typo is refused, by name, while the person who typed
 * it is still looking at it.
 *
 * The executor is the opposite and needs the same treatment for the opposite
 * reason: an unknown ACTION throws inside the worker, hours later, in a log
 * nobody opened.
 */
final class SaveRuleRequest extends FormRequest
{
    /** Mirrors the evaluator's own bound. Anything deeper is refused here so
     *  it cannot be silently ignored there. */
    private const MAX_DEPTH = 8;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'trigger' => [$required, 'string', 'in:'.implode(',', WorkflowRuleModel::TRIGGERS)],
            'conditions' => ['sometimes', 'array'],
            'actions' => [$required, 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', 'in:'.implode(',', ActionExecutor::types())],
            'actions.*.with' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            // Order matters when two rules act on the same change, and a rule
            // with no stated order runs after the ones that have one.
            'run_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('conditions') || ! $this->has('conditions')) {
                return;
            }

            foreach ($this->refusals($this->array('conditions'), 0) as $refusal) {
                $validator->errors()->add('conditions', $refusal);
            }
        });
    }

    /**
     * Everything wrong with the predicate, not the first thing wrong with it.
     *
     * One error per save is a queue of round trips through a form somebody is
     * composing; the reachability guard reports its offenders together for the
     * same reason.
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    private function refusals(array $node, int $depth): array
    {
        if ($node === []) {
            return [];
        }

        if ($depth > self::MAX_DEPTH) {
            return ['That condition is nested deeper than the engine will read.'];
        }

        foreach (['all', 'any'] as $group) {
            if (isset($node[$group])) {
                if (! is_array($node[$group])) {
                    return ["`{$group}` takes a list of conditions."];
                }

                $refusals = [];

                foreach ($node[$group] as $child) {
                    $refusals = [...$refusals, ...$this->refusals((array) $child, $depth + 1)];
                }

                return $refusals;
            }
        }

        if (isset($node['not'])) {
            return $this->refusals((array) $node['not'], $depth + 1);
        }

        return $this->leafRefusals($node);
    }

    /**
     * @param  array<mixed>  $leaf
     * @return list<string>
     */
    private function leafRefusals(array $leaf): array
    {
        $refusals = [];
        $field = $leaf['field'] ?? null;
        $operator = $leaf['op'] ?? 'eq';

        if (! is_string($field) || $field === '') {
            $refusals[] = 'Every condition names a field.';
        } elseif (! RuleVocabulary::knows($field)) {
            // Named, so the message is usable: "invalid condition" sends
            // somebody back to compare two screens character by character.
            $refusals[] = "`{$field}` is not a fact this system supplies to a rule.";
        }

        if (! is_string($operator) || ! in_array($operator, ConditionEvaluator::OPERATORS, strict: true)) {
            $refusals[] = 'That comparison is not one the engine can make.';
        }

        return $refusals;
    }
}
