/** Mirrors CalendarQuery's event shape (docs/10, Phase 5). */

export type CalendarSource = "work" | "milestones" | "recurring";

export type CalendarEvent = {
  type: "work_item" | "milestone" | "recurring";
  id: string;
  title: string;
  reference: string | null;
  starts_at: string;
  all_day: boolean;
  project: string | null;
  state: string | null;
  /** True for an occurrence a recurrence rule will produce but has not yet. */
  is_projected: boolean;
};

export type CalendarWindow = {
  from: string;
  to: string;
  sources: CalendarSource[];
};

export type FeedStatus = {
  id: string;
  created_at: string;
  last_accessed_at: string | null;
} | null;
