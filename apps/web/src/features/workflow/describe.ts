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
 */

/** Mirrors ConditionEvaluator::OPERATORS. An operator missing here is a
 *  vocabulary this build does not have, and a reason to show the raw rule. */
const OPERATORS: Record<string, (field: string, value: unknown) => string> = {
  eq: (field, value) => `${field} is ${literal(value)}`,
  neq: (field, value) => `${field} is not ${literal(value)}`,
  in: (field, value) => `${field} is one of ${list(value)}`,
  not_in: (field, value) => `${field} is none of ${list(value)}`,
  gt: (field, value) => `${field} is more than ${literal(value)}`,
  gte: (field, value) => `${field} is at least ${literal(value)}`,
  lt: (field, value) => `${field} is less than ${literal(value)}`,
  lte: (field, value) => `${field} is at most ${literal(value)}`,
  contains: (field, value) => `${field} contains ${literal(value)}`,
  is_null: (field) => `${field} is empty`,
  is_not_null: (field) => `${field} is set`,
  changed_to: (_field, value) => `it changes to ${literal(value)}`,
  changed_from: (_field, value) => `it changes from ${literal(value)}`,
};

const TRIGGERS: Record<string, string> = {
  "work_item.status_changed": "work changes status",
  "work_item.assigned": "work is assigned",
  "work_item.created": "work is created",
  "approval.decided": "an approval is decided",
  "schedule.due_soon": "work is due soon",
  "schedule.overdue": "work is overdue",
};

const ACTIONS: Record<string, string> = {
  notify: "Notify",
  assign: "Assign",
  transition: "Move",
  create_approval: "Open an approval",
  escalate: "Escalate",
};

/** The trigger in words, or the key itself. */
export function describeTrigger(trigger: string): string {
  const phrase = TRIGGERS[trigger];

  return phrase ? `When ${phrase}` : trigger;
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
export function describeCondition(condition: RuleCondition): string[] | null {
  // An empty predicate matches everything, and the evaluator says so.
  if (Object.keys(condition).length === 0) return ["Every time it happens"];

  return lines(condition, 0);
}

function lines(node: RuleCondition, depth: number): string[] | null {
  // The evaluator stops at depth 8; a predicate that deep is past the point
  // where a sentence helps anyone anyway.
  if (depth > 4) return null;

  if (Array.isArray(node.all)) {
    return flatten(node.all.map((child) => lines(asNode(child), depth + 1)));
  }

  if (Array.isArray(node.any)) {
    const children = flatten(node.any.map((child) => lines(asNode(child), depth + 1)));

    if (!children) return null;

    // One line, so the "or" cannot be misread as another "and" in a list.
    return [`any of: ${children.join(" · or · ")}`];
  }

  if (node.not !== undefined) {
    const child = lines(asNode(node.not), depth + 1);

    return child ? [`not: ${child.join(" and ")}`] : null;
  }

  return leaf(node);
}

function leaf(node: RuleCondition): string[] | null {
  const field = node.field;
  const operator = typeof node.op === "string" ? node.op : "eq";
  const render = OPERATORS[operator];

  if (typeof field !== "string" || !render) return null;

  return [render(field, node.value)];
}

/**
 * One action in words, or `null`.
 *
 * The action's NAME is translated — that set is closed and a code change to
 * extend. Its configuration is printed as it is stored: the handlers read keys
 * this file has no list of, and a summary that quietly drops one would be a
 * description of a different action.
 */
export function describeAction(action: RuleAction): { verb: string; config: string[] } | null {
  const verb = ACTIONS[action.type];

  if (!verb) return null;

  const config = Object.entries(action.with ?? {}).map(
    ([key, value]) => `${key}: ${literal(value)}`,
  );

  return { verb, config };
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

function list(value: unknown): string {
  return Array.isArray(value) ? value.map(literal).join(", ") : literal(value);
}

function literal(value: unknown): string {
  if (value === null || value === undefined) return "nothing";
  if (typeof value === "boolean") return value ? "yes" : "no";
  if (Array.isArray(value) || typeof value === "object") return JSON.stringify(value);

  return String(value);
}
