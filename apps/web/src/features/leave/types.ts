/** Leave (ADR 0063), as the API sends it. */
export type LeavePolicy = {
  preset: string | null;
  period: "calendar_year" | "hire_anniversary";
  accrual: "upfront" | "monthly" | "monthly_first_year";
  base_days: number;
  probation_months: number;
  carry_over_max_days: number;
  carry_over_until_month: number | null;
  approval: "manager" | "hr" | "manager_then_hr";
  working_days: number[];
  tenure_bonus: Array<{ years: number; days: number }>;
  level_bonus: Partial<Record<JobLevel, number>>;
  updated_at: string;
};

export type JobLevel = "staff" | "supervisor" | "manager" | "director";

export const JOB_LEVELS: JobLevel[] = ["staff", "supervisor", "manager", "director"];

export type LeaveType = {
  id: string;
  key: string;
  name: string;
  paid: boolean;
  uses_quota: boolean;
  after_probation: boolean;
  day_basis: "working_days" | "calendar_days";
  max_days_per_request: number | null;
  attachment_after_days: number | null;
  allow_half_day: boolean;
  is_active: boolean;
  sort_order: number;
};

export type LeaveWarning = { code: string; minimum: number };

export type LeaveSettingsData = {
  policy: LeavePolicy | null;
  types: LeaveType[];
  warnings: LeaveWarning[];
  presets: string[];
  levels: JobLevel[];
};

export type Holiday = { id: string; on_date: string; name: string; kind: "public" | "collective" };

export type LeaveBalance = {
  period_start: string;
  period_end: string;
  entitlement: number;
  earned: number;
  carried: number;
  carry_expires_on: string | null;
  used: number;
  pending: number;
  available: number;
  probation_ends_on: string;
  hired_on: string | null;
};

export type LeaveStatus = "pending" | "approved" | "rejected" | "cancelled";

export type LeaveRequest = {
  id: string;
  person: { id: string; name: string };
  type: { id: string; key: string; name: string };
  uses_quota: boolean;
  paid: boolean;
  starts_on: string;
  ends_on: string;
  half_day: "am" | "pm" | null;
  days: number;
  reason: string | null;
  document_required: boolean;
  status: LeaveStatus;
  step: "manager" | "hr" | null;
  decided_by: string | null;
  decided_at: string | null;
  decision_note: string | null;
  created_at: string;
  /** Only on the approvals queue, for quota types. */
  balance?: LeaveBalance | null;
};

export type MyLeave = { balance: LeaveBalance | null; requests: LeaveRequest[]; types: LeaveType[] };

export type LeaveQuote = { days: number; uses_quota: boolean; available: number | null; document_required: boolean };
