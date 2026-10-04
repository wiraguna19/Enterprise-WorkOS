export type KpiSubjectType = "team" | "department" | "project";

export type KpiStatus = "on_track" | "at_risk" | "off_track" | "no_data";

export type KpiPeriodValue = {
  period_start: string;
  /** The last day of the period, inclusive. */
  period_end: string;
  /** Still running: the value is "so far". */
  partial: boolean;
  value: number | null;
  status: KpiStatus;
  note: string | null;
};

export type Kpi = {
  id: string;
  name: string;
  description: string;
  subject: { type: KpiSubjectType; id: string; name: string | null; key: string | null };
  /** `manual`, or one of the computed sources (ADR 0062). */
  source: string;
  /** For a computed KPI one of `items`, `hours`, `percent`; for a manual one, free text. */
  unit: string;
  direction: "higher" | "lower";
  target: number;
  period: "week" | "month" | "quarter";
  archived: boolean;
  current: KpiPeriodValue;
  /** Oldest first, ending with the current period. */
  history: KpiPeriodValue[];
  can_manage: boolean;
};

export type KpiVocabulary = {
  sources: Array<{ key: string; unit: string | null; direction: "higher" | "lower" | null }>;
  periods: Array<"week" | "month" | "quarter">;
  subjects: Array<{ type: KpiSubjectType; id: string; name: string }>;
};
