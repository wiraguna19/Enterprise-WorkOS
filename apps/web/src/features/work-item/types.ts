/**
 * Mirrors the API contract (docs/05 §3).
 *
 * In a full build these are generated from the OpenAPI spec into
 * packages/api-client, so a backend change breaks the frontend typecheck in the
 * same CI run. Until the generator runs, this file is the contract — and it is
 * the one place to change when the API does.
 */

export type StateCategory =
  | "backlog"
  | "todo"
  | "in_progress"
  | "in_review"
  | "blocked"
  | "done"
  | "cancelled";

export type Priority = "low" | "medium" | "high" | "urgent";

export type WorkItem = {
  id: string;
  type: string;
  reference: string;
  title: string;
  description?: string;
  state: {
    id: string;
    key: string;
    label: string;
    category: StateCategory;
    color: string;
  } | null;
  state_category: StateCategory;
  priority: Priority;
  start_date: string | null;
  due_at: string | null;
  is_overdue: boolean;
  estimate_hours: number | null;
  position: number;
  project: { id: string; key: string; name: string } | null;
  parent_id: string | null;
  assignees?: Array<{
    assignment_id: string;
    membership_id: string;
    name: string | null;
    avatar_url: string | null;
    role: string;
    accepted: boolean;
  }>;
  subtask_count?: number;
  completed_at: string | null;
  created_at: string;
  lock_version: number;
  /**
   * The server's own authorization decision, echoed so the client knows which
   * controls to render. It describes the decision; it does not make it.
   */
  permissions: Record<string, boolean>;
};

/**
 * One legal move, as the server computed it (docs/07 §4).
 *
 * `available: false` arrives WITH a reason and is rendered disabled, not
 * dropped: a control that vanishes reads as a bug, while a disabled one with an
 * explanation teaches the workflow.
 */
export type Transition = {
  id: string;
  label: string;
  to_state: {
    id: string;
    key: string;
    label: string;
    category: StateCategory;
    color: string;
  };
  requires_comment: boolean;
  /**
   * True when this move is reachable from any state — Blocked, Cancelled. An
   * escape hatch belongs in the menu, never as the suggested next step.
   */
  is_escape_hatch: boolean;
  available: boolean;
  blocked_reason: string | null;
};

export type Approval = {
  id: string;
  status: "pending" | "approved" | "changes_requested" | "rejected" | "withdrawn";
  policy: "any_one" | "all_of" | "quorum";
  required_approvals: number;
  submission_note: string | null;
  submitted_at: string;
  resolved_at: string | null;
  /**
   * Named as the API names it, and optional as the API sends it: the field is
   * `whenLoaded('requester')`, so it is absent — not null — from any response
   * that did not eager load the relation.
   */
  requested_by?: { membership_id: string; name: string | null };
  subject: {
    type: string;
    reference: string;
    title: string;
    priority: Priority;
    state_category: StateCategory;
    due_at: string | null;
  } | null;
  approvers: Array<{ membership_id: string; name: string | null }>;
  decisions: Array<{
    id: string;
    decision: "approved" | "changes_requested" | "rejected";
    comment: string | null;
    decided_at: string;
    reviewer: string | null;
  }>;
  permissions: Record<string, boolean>;
};

/**
 * A comment, as the API returns it.
 *
 * `body_markdown` is the source and `body_html` is what renders: an editor
 * seeded from the HTML would rewrite the comment into whatever the renderer
 * produced, which never round-trips. The page and the thread component read the
 * same type on purpose — this file existed while the page kept a private copy
 * of it, which is the drift this file is here to prevent.
 */
export type Comment = {
  id: string;
  author: { membership_id: string; name: string | null; avatar_url: string | null };
  body_markdown: string;
  body_html: string;
  parent_id: string | null;
  created_at: string;
  edited: boolean;
};

/**
 * A notification, as the API actually returns it.
 *
 * This type used to declare `payload`, `subject_type` and `subject_id` — none
 * of which `NotificationResource` emits. TypeScript believed it, the inbox read
 * `payload.reference` on every row, got `undefined` on every row, and so every
 * notification in the product read "… an item" and linked back to the inbox
 * instead of to the work. It looked plausible enough to survive four phases.
 *
 * `message` is composed server-side on purpose, so the inbox, a future email
 * and a future push cannot drift apart. The client renders it rather than
 * rebuilding it — the copy it used to keep was both a duplicate and dead.
 */
export type Notification = {
  id: string;
  type: string;
  subject: {
    type: string;
    id: string;
    reference: string | null;
    title: string | null;
  };
  actor: { membership_id: string | null; name: string | null };
  message: string;
  read: boolean;
  created_at: string;
};

export type Project = {
  id: string;
  key: string;
  name: string;
  description?: string;
  status: string;
  priority: Priority;
  visibility: "internal" | "private";
  start_date: string | null;
  end_date: string | null;
  progress: number;
  progress_as_of: string | null;
  member_count?: number;
  open_work_count?: number;
  overdue_work_count?: number;
  archived: boolean;
  permissions: Record<string, boolean>;
};

export type BoardColumn = {
  state: {
    id: string;
    key: string;
    label: string;
    category: StateCategory;
    color: string;
    position: number;
  };
  /**
   * Every card in this state, which is NOT `items.length`.
   *
   * The board hands over the top of each column and says how much it left; the
   * count is a fact about the column and the cards are a list for the reader.
   * Rendering `items.length` as the column count would understate a busy
   * project and look entirely correct doing it.
   */
  total: number;
  hidden_count: number;
  items: WorkItem[];
};
