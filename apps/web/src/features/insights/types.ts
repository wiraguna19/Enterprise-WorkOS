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
