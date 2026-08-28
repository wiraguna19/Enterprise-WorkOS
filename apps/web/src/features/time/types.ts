/** Mirrors the time endpoints (docs/03 §4). */

export type TimeEntry = {
  id: string;
  hours: number;
  logged_on?: string;
  note: string;
  membership_id?: string;
  person?: string | null;
  logged_at: string;
  work_item?: {
    reference: string;
    title: string;
    state_category: string;
  } | null;
};

export type TimesheetDay = {
  date: string;
  hours: number;
  entries: TimeEntry[];
};

export type TimesheetMeta = {
  from: string;
  to: string;
  total_hours: number;
  days_logged: number;
};
