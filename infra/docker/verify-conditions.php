<?php

declare(strict_types=1);

/**
 * Rule condition evaluator harness.
 *
 * The evaluator decides whether customer-authored automation fires. Two failure
 * modes matter and they are opposite:
 *
 *   - a rule that fires when it should not runs an action nobody intended
 *   - a rule that silently never fires looks like the feature is broken
 *
 * Both are invisible in review, so the grammar is exercised directly here —
 * including the malformed input a rule builder will eventually produce.
 *
 * Run: php infra/docker/verify-conditions.php
 */

spl_autoload_register(function (string $class): void {
    $path = __DIR__.'/../../apps/api/app/'.str_replace(
        ['App\\Modules\\', '\\'],
        ['Modules/', '/'],
        $class,
    ).'.php';

    if (file_exists($path)) {
        require $path;
    }
});

use App\Modules\Workflow\Domain\ConditionEvaluator;

$evaluator = new ConditionEvaluator;

/** The facts a status-change trigger supplies. */
$facts = [
    'type' => 'task',
    'priority' => 'high',
    'state_category' => 'in_review',
    'from_category' => 'in_progress',
    'to_category' => 'in_review',
    'to_state_key' => 'in_review',
    'estimate_hours' => 8,
    'project_key' => 'ENG',
    'title' => 'Implement assignment history',
    'assignee_membership_id' => null,
    'days_overdue' => 3,
];

/** @var list<array{0:string,1:array<string,mixed>,2:bool}> */
$cases = [
    // ── the empty predicate ─────────────────────────────────────────────────
    ['empty matches everything', [], true],

    // ── leaf comparisons ────────────────────────────────────────────────────
    ['eq hit', ['field' => 'priority', 'op' => 'eq', 'value' => 'high'], true],
    ['eq miss', ['field' => 'priority', 'op' => 'eq', 'value' => 'low'], false],
    ['neq', ['field' => 'priority', 'op' => 'neq', 'value' => 'low'], true],
    ['in hit', ['field' => 'priority', 'op' => 'in', 'value' => ['high', 'urgent']], true],
    ['in miss', ['field' => 'priority', 'op' => 'in', 'value' => ['low', 'medium']], false],
    ['not_in', ['field' => 'priority', 'op' => 'not_in', 'value' => ['low']], true],
    ['gt', ['field' => 'estimate_hours', 'op' => 'gt', 'value' => 4], true],
    ['gte boundary', ['field' => 'estimate_hours', 'op' => 'gte', 'value' => 8], true],
    ['lt', ['field' => 'estimate_hours', 'op' => 'lt', 'value' => 4], false],
    ['contains, case-insensitive', ['field' => 'title', 'op' => 'contains', 'value' => 'ASSIGNMENT'], true],
    ['is_null on a null fact', ['field' => 'assignee_membership_id', 'op' => 'is_null'], true],
    ['is_not_null on a null fact', ['field' => 'assignee_membership_id', 'op' => 'is_not_null'], false],

    // JSON has no integer/string distinction once it round-trips through a
    // form builder, so "8" and 8 must behave identically.
    ['string 8 equals numeric 8', ['field' => 'estimate_hours', 'op' => 'eq', 'value' => '8'], true],
    ['numeric comparison from a string', ['field' => 'estimate_hours', 'op' => 'gt', 'value' => '4'], true],

    // ── transition-aware operators ──────────────────────────────────────────
    // The reason these exist: a plain equality on state_category would also
    // match every later edit while the item SITS in review, firing the rule
    // repeatedly. changed_to fires only on the move itself.
    ['changed_to category', ['field' => 'x', 'op' => 'changed_to', 'value' => 'in_review'], true],
    ['changed_to wrong target', ['field' => 'x', 'op' => 'changed_to', 'value' => 'done'], false],
    ['changed_from category', ['field' => 'x', 'op' => 'changed_from', 'value' => 'in_progress'], true],

    // ── boolean composition ─────────────────────────────────────────────────
    ['all, both true', ['all' => [
        ['field' => 'type', 'op' => 'eq', 'value' => 'task'],
        ['field' => 'priority', 'op' => 'in', 'value' => ['high', 'urgent']],
    ]], true],
    ['all, one false', ['all' => [
        ['field' => 'type', 'op' => 'eq', 'value' => 'task'],
        ['field' => 'priority', 'op' => 'eq', 'value' => 'low'],
    ]], false],
    ['any, one true', ['any' => [
        ['field' => 'priority', 'op' => 'eq', 'value' => 'low'],
        ['field' => 'type', 'op' => 'eq', 'value' => 'task'],
    ]], true],
    ['any, none true', ['any' => [
        ['field' => 'priority', 'op' => 'eq', 'value' => 'low'],
        ['field' => 'type', 'op' => 'eq', 'value' => 'incident'],
    ]], false],
    ['not', ['not' => ['field' => 'priority', 'op' => 'eq', 'value' => 'low']], true],
    ['nested all inside any', ['any' => [
        ['all' => [
            ['field' => 'type', 'op' => 'eq', 'value' => 'incident'],
            ['field' => 'priority', 'op' => 'eq', 'value' => 'urgent'],
        ]],
        ['all' => [
            ['field' => 'type', 'op' => 'eq', 'value' => 'task'],
            ['field' => 'days_overdue', 'op' => 'gte', 'value' => 3],
        ]],
    ]], true],

    // ── malformed input: must SKIP, never fire and never throw ──────────────
    // Every one of these is something a rule builder or a hand-edited JSON
    // column will eventually produce. The rule must not fire on a predicate
    // the engine does not understand: guessing could run an action nobody
    // intended.
    ['unknown operator', ['field' => 'priority', 'op' => 'approximately', 'value' => 'high'], false],
    ['unknown field', ['field' => 'nonexistent', 'op' => 'eq', 'value' => 'x'], false],
    ['missing field key', ['op' => 'eq', 'value' => 'high'], false],
    ['field is not a string', ['field' => ['a'], 'op' => 'eq', 'value' => 'x'], false],
    ['gt against a non-numeric fact', ['field' => 'title', 'op' => 'gt', 'value' => 5], false],
    ['contains against a null fact', ['field' => 'assignee_membership_id', 'op' => 'contains', 'value' => 'x'], false],
    ['empty all matches (vacuous truth)', ['all' => []], true],
    ['empty any matches nothing', ['any' => []], false],
];

$failures = 0;

foreach ($cases as [$label, $condition, $expected]) {
    $actual = $evaluator->matches($condition, $facts);

    if ($actual !== $expected) {
        $failures++;
        printf("FAIL %-36s expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true));

        continue;
    }

    printf("ok   %-36s %s\n", $label, $actual ? 'matches' : 'skips');
}

// ── depth guard ─────────────────────────────────────────────────────────────
// A predicate nested past the limit must return false rather than recursing —
// this runs inside a queued job, and a stack overflow there takes the worker
// down and backs up every other rule behind it.
$deep = ['field' => 'type', 'op' => 'eq', 'value' => 'task'];
for ($i = 0; $i < 12; $i++) {
    $deep = ['all' => [$deep]];
}

$deepResult = $evaluator->matches($deep, $facts);

if ($deepResult !== false) {
    $failures++;
    echo "FAIL depth guard did not trip on a 12-level predicate\n";
} else {
    echo "ok   depth guard                         refuses a 12-level predicate\n";
}

// The evaluator must be TOTAL: no input produces an exception, because an
// exception here counts toward disabling a rule that is merely misconfigured.
$hostile = [
    ['all' => 'not-an-array'],
    ['any' => null],
    ['not' => 'string'],
    ['field' => 'priority', 'op' => 'in', 'value' => 'not-an-array'],
];

foreach ($hostile as $index => $condition) {
    try {
        $evaluator->matches($condition, $facts);
        printf("ok   hostile input %-22d returned without throwing\n", $index);
    } catch (Throwable $e) {
        $failures++;
        printf("FAIL hostile input %-22d threw %s: %s\n", $index, $e::class, $e->getMessage());
    }
}

$total = count($cases) + 1 + count($hostile);

echo "\n", $failures === 0
    ? "All {$total} condition cases passed.\n"
    : "{$failures} of {$total} condition cases FAILED.\n";

exit($failures === 0 ? 0 : 1);
