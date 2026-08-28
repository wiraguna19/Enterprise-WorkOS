<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

/**
 * Evaluates a JSON predicate tree against a subject.
 *
 * Pure, framework-free, and total: every input produces a boolean, never an
 * exception. That matters because this runs inside a queued job over
 * customer-authored conditions — a malformed predicate must skip the rule, not
 * kill the worker and leave the queue backed up (docs/02 §7).
 *
 * Grammar, deliberately small:
 *
 *   { "all": [ <node>, ... ] }          every child must hold
 *   { "any": [ <node>, ... ] }          at least one child must hold
 *   { "not": <node> }                   negation
 *   { "field": "priority",              a leaf comparison
 *     "op": "in",
 *     "value": ["high","urgent"] }
 *
 * An empty condition matches everything, which is what "run this rule on every
 * status change" should mean.
 */
final class ConditionEvaluator
{
    /** Guards against a pathological or hand-crafted deeply nested predicate. */
    private const MAX_DEPTH = 8;

    private const OPERATORS = [
        'eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte',
        'contains', 'is_null', 'is_not_null', 'changed_to', 'changed_from',
    ];

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $subject  flattened facts about the subject
     */
    public function matches(array $condition, array $subject, int $depth = 0): bool
    {
        // An empty predicate is "always" — the common case for a rule that
        // should fire on every occurrence of its trigger.
        if ($condition === []) {
            return true;
        }

        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        if (isset($condition['all'])) {
            foreach ((array) $condition['all'] as $child) {
                if (! $this->matches((array) $child, $subject, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['any'])) {
            foreach ((array) $condition['any'] as $child) {
                if ($this->matches((array) $child, $subject, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($condition['not'])) {
            return ! $this->matches((array) $condition['not'], $subject, $depth + 1);
        }

        return $this->compare($condition, $subject);
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, mixed>  $subject
     */
    private function compare(array $leaf, array $subject): bool
    {
        $field = $leaf['field'] ?? null;
        $operator = $leaf['op'] ?? 'eq';

        if (! is_string($field) || ! in_array($operator, self::OPERATORS, strict: true)) {
            // An unknown field or operator means the rule was authored against
            // a vocabulary this version does not have. Skipping is the only
            // safe reading: guessing could fire an action nobody intended.
            return false;
        }

        $actual = $subject[$field] ?? null;
        $expected = $leaf['value'] ?? null;

        return match ($operator) {
            'eq' => $this->scalar($actual) === $this->scalar($expected),
            'neq' => $this->scalar($actual) !== $this->scalar($expected),
            'in' => in_array($this->scalar($actual), array_map($this->scalar(...), (array) $expected), strict: true),
            'not_in' => ! in_array($this->scalar($actual), array_map($this->scalar(...), (array) $expected), strict: true),
            'gt' => $this->numeric($actual) > $this->numeric($expected),
            'gte' => $this->numeric($actual) >= $this->numeric($expected),
            'lt' => $this->numeric($actual) < $this->numeric($expected),
            'lte' => $this->numeric($actual) <= $this->numeric($expected),
            'contains' => is_string($actual)
                && is_string($expected)
                && str_contains(mb_strtolower($actual), mb_strtolower($expected)),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,

            // Transition-aware operators. These read the before/after facts the
            // trigger supplies, which is how "when it BECOMES in_review" is
            // expressible at all — a plain equality would also match every
            // later edit while it sits in review.
            'changed_to' => $this->scalar($subject['to_category'] ?? null) === $this->scalar($expected)
                || $this->scalar($subject['to_state_key'] ?? null) === $this->scalar($expected),
            'changed_from' => $this->scalar($subject['from_category'] ?? null) === $this->scalar($expected)
                || $this->scalar($subject['from_state_key'] ?? null) === $this->scalar($expected),

            default => false,
        };
    }

    /** Comparisons are string-based so `"3"` and `3` from JSON behave alike. */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /** Non-numeric input yields NAN, and every comparison against it is false. */
    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : NAN;
    }
}
