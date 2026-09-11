import type { StateCategory } from "@/components/ui/StatusChip";

/**
 * The shapes `GET /workflows` and `GET /workflow-rules` send (docs/05 §3).
 *
 * Hand-written, like every type in this client, and therefore only as true as
 * the last line that read it: `ApprovalResource` emitted `reviewers` while this
 * app's type said `approvers` for three phases and compiled the whole time,
 * because nothing rendered the field. These types are read by the screens in
 * this folder on their first commit, which is the only thing that checks them.
 */
export type WorkflowState = {
  id: string;
  key: string;
  label: string;
  /** What everything keys off. The label is a customer's word (docs/02 §7). */
  category: StateCategory;
  color: string;
  is_initial: boolean;
  is_terminal: boolean;
  requires_approval: boolean;
};

export type WorkflowTransition = {
  id: string;
  /** NULL is "from anywhere" — the fan-out is the fact, so it is kept as one. */
  from_state_id: string | null;
  to_state_id: string;
  label: string;
  requires_comment: boolean;
  /**
   * Whether a guard exists, never what it says. The guard is a predicate over
   * an item and an actor that this endpoint has neither of; only
   * `available-transitions` can answer whether a particular move is open to a
   * particular person right now.
   */
  is_guarded: boolean;
};

export type Workflow = {
  id: string;
  name: string;
  applies_to_type: string;
  version: number;
  is_default: boolean;
  states: WorkflowState[];
  transitions: WorkflowTransition[];
};

export type RuleTrigger =
  | "work_item.status_changed"
  | "work_item.assigned"
  | "work_item.created"
  | "approval.decided"
  | "schedule.due_soon"
  | "schedule.overdue";

export type RuleCondition = Record<string, unknown>;

export type RuleAction = {
  type: string;
  with?: Record<string, unknown>;
};

export type Rule = {
  id: string;
  name: string;
  description: string;
  /** Typed wide on purpose: a rule authored against a newer vocabulary must
   *  render as itself rather than as nothing. */
  trigger: RuleTrigger | string;
  conditions: RuleCondition;
  actions: RuleAction[];
  is_active: boolean;
  health: {
    healthy: boolean;
    failure_count: number;
    disabled_reason: string | null;
  };
};

export type RuleRun = {
  id: string;
  subject_type: string;
  subject_id: string;
  outcome: string;
  matched: boolean;
  actions_run: number | null;
  error: string | null;
  duration_ms: number | null;
  occurred_at: string;
};
