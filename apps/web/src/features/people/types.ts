import type { StateCategory } from "@/features/work-item/types";

/**
 * Mirrors PersonResource (docs/05 §3).
 *
 * Two types for one endpoint, because the API sends two shapes: the directory
 * sends what you compare people BY, and the profile adds what you look one
 * person UP for. Modelling the extra fields as optional on a single type would
 * let a directory row read `person.manager` and silently render nothing.
 */

export type Person = {
  id: string;
  name: string;
  /** Null once erased: an erased person has no address to show (ADR 0022). */
  email: string | null;
  status: string;
  /** Set once this person has been erased from this organization (ADR 0022). */
  erased_at: string | null;
  joined_at: string | null;
  job_title: string | null;
  employment_type: string | null;
  weekly_capacity_hours: string | null;
  department: { id: string; name: string } | null;
  permissions: Record<string, boolean>;
};

/** A colleague as referenced from someone else's reporting line. */
export type PersonRef = {
  id: string;
  name: string | null;
  job_title: string | null;
};

export type PersonDetail = Person & {
  /** Whether a second factor is on — sent only to somebody who may take it
   *  off, and null to everybody else (ADR 0031). */
  mfa_enabled: boolean | null;
  roles: Array<{ id: string; key: string; name: string }>;
  manager: PersonRef | null;
  direct_reports: PersonRef[];
  work_location: string | null;
  hired_at: string | null;
  /** Only sent to someone who can edit the record; absent for everyone else. */
  employee_number?: string | null;
};

/** Mirrors WorkloadQuery (docs/02 §11). */
export type Workload = {
  membership_id: string;
  week_start: string;
  week_end: string;
  capacity_hours: number;
  /** Null, not zero: nothing in the schema records leave. */
  time_off_hours: number | null;
  committed_hours: number;
  utilization: number | null;
  item_count: number;
  unestimated_count: number;
  /** Committed work that carries no dates, so it lands in no week. */
  undated_count: number;
  default_estimate_hours: number;
};

/**
 * One item behind a person's committed hours, as the drill-through returns it.
 *
 * `share_hours` is what this item contributed to the figure — not its estimate.
 * An item spanning three weeks contributes a slice to each, and printing the
 * estimate here would produce a list whose numbers do not add up to the bar
 * above it (docs/10, ADR 0009).
 */
export type WorkloadItem = {
  id: string;
  reference: string;
  title: string;
  state_category: StateCategory;
  project: string | null;
  start_date: string | null;
  due_at: string | null;
  estimate_hours: number | null;
  /** Null where the item could not be placed in this week at all. */
  share_hours: number | null;
  /** True where the hours are the organization's default, not an estimate. */
  counted_at_default: boolean;
};

/** The summary the drill-through echoes, plus what the reader cannot see. */
export type WorkloadItemsMeta = Workload & { hidden_count: number };
