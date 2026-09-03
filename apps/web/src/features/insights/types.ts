/** Mirrors FlowQuery (ADR 0007). */

export type FlowWeek = {
  week_start: string;
  throughput: number;
  /** Completions with an `in_progress` transition to measure from. */
  measured: number;
  cycle_time_p50_hours: number | null;
  cycle_time_p85_hours: number | null;
  /** Completions that carried a due date — the late rate's denominator. */
  dated: number;
  completed_late: number;
  /** Null, not zero, when nothing in the week carried a date (ADR 0010). */
  late_rate: number | null;
};

export type FlowDepartment = {
  /** Null for work with no project, or a project with no department. */
  department_id: string | null;
  name: string | null;
  throughput: number;
  late: number;
};

export type Flow = {
  from: string;
  to: string;
  throughput: number;
  measured: number;
  /** Completions with nothing to measure. Never counted as zero. */
  unmeasurable: number;
  cycle_time_p50_hours: number | null;
  cycle_time_p85_hours: number | null;
  dated: number;
  completed_late: number;
  late_rate: number | null;
  weeks: FlowWeek[];
  departments: FlowDepartment[];
};

/** Where work waited (ADR 0010). One row per state category. */
export type Bottleneck = {
  category: string;
  /** Null where nothing left this category in the window. */
  median_hours: number | null;
  p85_hours: number | null;
  steps: number;
  /** A snapshot: it changes when time passes, not when work happens. */
  waiting_now: number;
};

/** One completion behind the numbers above. Mirrors FlowController::items. */
export type FlowCompletion = {
  id: string;
  reference: string;
  title: string;
  project: string | null;
  completed_at: string;
  /** Null where the item never entered In Progress. Never a zero (ADR 0007). */
  cycle_time_hours: number | null;
  /** Null where the item had no due date to miss (ADR 0010). */
  late: boolean | null;
};

export type FlowCompletionsMeta = {
  from: string;
  to: string;
  /** Every completion in the window, before the reader's visibility applies. */
  throughput: number;
  /** How many of those this reader may not see. Reconciles the list to the total. */
  hidden_count: number;
};

/** Project health (ADR 0008). Five signals, no composite score. */

export type HealthStatus = "on_track" | "at_risk" | "off_track" | "unknown";

type Signal = { status: HealthStatus };

export type Health = {
  status: HealthStatus;
  /** Null when the project has no countable work — never a zero. */
  progress_percent: number | null;
  open_count: number;
  done_count: number;
  signals: {
    schedule: Signal & {
      end_date: string | null;
      days_remaining: number | null;
      open_count?: number;
    };
    overdue_work: Signal & { count: number; open_count: number; share: number };
    blocked_work: Signal & { count: number; longest_days: number | null };
    milestones: Signal & { count: number; past_due_count: number; missed_count: number };
    activity: Signal & {
      last_activity_at: string | null;
      days_since: number | null;
      stale_count: number;
      open_count?: number;
    };
  };
  past_due_milestones: Array<{
    id: string;
    name: string;
    due_date: string | null;
    status: string;
  }>;
  thresholds: {
    schedule_warning_days: number;
    overdue_off_track_share: number;
    blocked_off_track_days: number;
    activity_at_risk_days: number;
    activity_off_track_days: number;
  };
};

export type HealthItem = {
  id: string;
  reference: string;
  title: string;
  state_category: string;
  project: string | null;
  due_at: string | null;
};

export type HealthItemsMeta = {
  signal: string;
  project: string;
  /** What the signal counted, before the reader's visibility narrowed it. */
  total: number;
  hidden_count: number;
};

/** Manager Home's risk list (ADR 0009). A row states why it is here. */

export type RiskReason = "overdue" | "blocking" | "unassigned" | "stalled" | "blocked";

export type AtRiskItem = {
  id: string;
  reference: string;
  title: string;
  state_category: string;
  project: string | null;
  due_at: string | null;
  assignee: string | null;
  /** Every reason that applies, worst first. Never a score. */
  reasons: RiskReason[];
  blocking_count: number;
  days_since_move: number;
};
