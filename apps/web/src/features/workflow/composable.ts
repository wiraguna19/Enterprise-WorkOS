import type { Rule, RuleAction, RuleCondition } from "./types";

/**
 * Can this builder edit this rule without losing part of it?
 *
 * The builder composes one shape: a flat `all` of leaf comparisons, and actions
 * of the types it has controls for. The engine accepts far more — nested
 * `any`/`not`, five action types, arbitrary config — and a form that silently
 * flattened a predicate it did not understand would DELETE the half it could
 * not draw.
 *
 * So a rule outside that shape is shown, in full, and not offered for editing.
 * The recurrence picker made the same choice about RRULE: covering four shapes
 * and saying so is more honest than a field that implies mastery. **A
 * placeholder that refuses does not rot.**
 */
export const BUILDABLE_ACTIONS = ["notify", "escalate"] as const;

export type Leaf = { field: string; op: string; value: unknown };

export function leaves(conditions: RuleCondition): Leaf[] | null {
  if (Object.keys(conditions).length === 0) return [];

  const all = conditions.all;

  if (!Array.isArray(all)) return null;

  const parsed: Leaf[] = [];

  for (const child of all) {
    if (typeof child !== "object" || child === null) return null;

    const leaf = child as Record<string, unknown>;

    // A nested group inside `all` is exactly the case that must not be
    // flattened: "any of these three" would become "all of these three".
    if (!("field" in leaf) || typeof leaf.field !== "string") return null;

    parsed.push({
      field: leaf.field,
      op: typeof leaf.op === "string" ? leaf.op : "eq",
      value: leaf.value,
    });
  }

  return parsed;
}

export function isBuildable(rule: Rule): boolean {
  return (
    leaves(rule.conditions) !== null &&
    rule.actions.every((action: RuleAction) =>
      (BUILDABLE_ACTIONS as readonly string[]).includes(action.type),
    )
  );
}
