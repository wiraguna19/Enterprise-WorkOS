/** Mirrors FlowQuery (ADR 0007). */

export type FlowWeek = {
  week_start: string;
  throughput: number;
  /** Completions with an `in_progress` transition to measure from. */
  measured: number;
  cycle_time_p50_hours: number | null;
  cycle_time_p85_hours: number | null;
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
  weeks: FlowWeek[];
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
};

export type FlowCompletionsMeta = {
  from: string;
  to: string;
  /** Every completion in the window, before the reader's visibility applies. */
  throughput: number;
  /** How many of those this reader may not see. Reconciles the list to the total. */
  hidden_count: number;
};
