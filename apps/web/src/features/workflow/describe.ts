import type { MessageKey } from "@/i18n/messages/en";
import type { Translator } from "@/i18n/translate";
import type { RuleAction, RuleCondition } from "./types";

/**
 * A rule in words — and the raw rule whenever words would be a guess.
 *
 * The recurrence picker set this rule and it holds here for the same reason:
 * **a wrong description is worse than a raw string.** Somebody reads "when
 * priority is high", believes it, and never opens the JSON again. So every
 * function in this file returns `null` the moment it meets something it cannot
 * translate exactly, and the caller prints the source instead.
 *
 * What is safe to translate is the CLOSED vocabulary — the operators
 * `ConditionEvaluator` implements, the triggers `WorkflowRuleModel::TRIGGERS`
 * lists, the handlers `ActionExecutor` registers. Field names are printed
 * verbatim: `days_overdue` is already English, and inventing "days late" would
 * be this file translating something it was not given.
 *
 * The words come from the reader's dictionary (ADR 0060), so every function
 * takes the translator. The sets below say only WHICH keys exist: a key absent
 * from them still means "show the raw rule", in every language alike.
 */

/** Mirrors ConditionEvaluator::OPERATORS. An operator missing here is a
 *  vocabulary this build does not have, and a reason to show the raw rule. */
export const OPERATORS = new Set([
  "eq",
  "neq",
  "in",
  "not_in",
  "gt",
  "gte",
  "lt",
  "lte",
  "contains",
  "is_null",
  "is_not_null",
  "changed_to",
  "changed_from",
]);

/** Operators whose value is a list, printed joined rather than as JSON. */
const LISTED = new Set(["in", "not_in"]);

const TRIGGERS = new Set([
  "work_item.status_changed",
  "work_item.assigned",
  "work_item.created",
  "approval.decided",
  "schedule.due_soon",
  "schedule.overdue",
]);

export const ACTIONS = new Set([
  "notify",
  "assign",
  "transition",
  "create_approval",
  "escalate",
  "webhook",
]);

/** The trigger in words, or the key itself. */
export function describeTrigger(trigger: string, t: Translator): string {
  return TRIGGERS.has(trigger) ? t(`trig.${trigger}` as MessageKey) : trigger;
}

/** An action's name in words, or the key itself. */
export function actionName(type: string, t: Translator): string {
  return ACTIONS.has(type) ? t(`ract.${type}` as MessageKey) : type;
}

/**
 * The predicate as a flat list of lines, or `null` if any part of it is
 * untranslatable.
 *
 * Flat and not nested because depth is where a rendered predicate stops being
 * readable and starts being a worse version of the JSON. `all` becomes one line
 * per child; anything the shape of `any` or `not` is kept explicit — an "any"
 * printed as a list would read as "all", which is the exact error this file
 * exists to avoid.
 */
export function describeCondition(condition: RuleCondition, t: Translator): string[] | null {
  // An empty predicate matches everything, and the evaluator says so.
  if (Object.keys(condition).length === 0) return [t("desc.always")];

  return lines(condition, 0, t);
}

function lines(node: RuleCondition, depth: number, t: Translator): string[] | null {
  // The evaluator stops at depth 8; a predicate that deep is past the point
  // where a sentence helps anyone anyway.
  if (depth > 4) return null;

  if (Array.isArray(node.all)) {
    return flatten(node.all.map((child) => lines(asNode(child), depth + 1, t)));
  }

  if (Array.isArray(node.any)) {
    const children = flatten(node.any.map((child) => lines(asNode(child), depth + 1, t)));

    if (!children) return null;

    // One line, so the "or" cannot be misread as another "and" in a list.
    return [t("desc.anyOf", { list: children.join(t("desc.or")) })];
  }

  if (node.not !== undefined) {
    const child = lines(asNode(node.not), depth + 1, t);

    return child ? [t("desc.not", { list: child.join(t("desc.and")) })] : null;
  }

  return leaf(node, t);
}

function leaf(node: RuleCondition, t: Translator): string[] | null {
  const field = node.field;
  const operator = typeof node.op === "string" ? node.op : "eq";

  if (typeof field !== "string" || !OPERATORS.has(operator)) return null;

  const value = LISTED.has(operator) ? list(node.value, t) : literal(node.value, t);

  return [t(`desc.op.${operator}` as MessageKey, { field, value })];
}

/**
 * One action in words, or `null`.
 *
 * The action's NAME is translated — that set is closed and a code change to
 * extend. Its configuration is printed as it is stored: the handlers read keys
 * this file has no list of, and a summary that quietly drops one would be a
 * description of a different action.
 */
export function describeAction(
  action: RuleAction,
  t: Translator,
): { verb: string; config: string[] } | null {
  if (!ACTIONS.has(action.type)) return null;

  const config = Object.entries(action.with ?? {}).map(
    ([key, value]) => `${key}: ${literal(value, t)}`,
  );

  return { verb: actionName(action.type, t), config };
}

function asNode(value: unknown): RuleCondition {
  return typeof value === "object" && value !== null ? (value as RuleCondition) : {};
}

/** One untranslatable child makes the whole predicate untranslatable. */
function flatten(parts: Array<string[] | null>): string[] | null {
  const all: string[] = [];

  for (const part of parts) {
    if (part === null) return null;

    all.push(...part);
  }

  return all;
}

function list(value: unknown, t: Translator): string {
  return Array.isArray(value)
    ? value.map((entry) => literal(entry, t)).join(", ")
    : literal(value, t);
}

function literal(value: unknown, t: Translator): string {
  if (value === null || value === undefined) return t("desc.nothing");
  if (typeof value === "boolean") return value ? t("desc.yes") : t("desc.no");
  if (Array.isArray(value) || typeof value === "object") return JSON.stringify(value);

  return String(value);
}
