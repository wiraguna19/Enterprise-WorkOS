export type ReportExport = {
  id: string;
  report: string;
  format: string;
  status: "pending" | "ready" | "failed" | "expired";
  parameters: Record<string, string>;
  filename: string | null;
  byte_size: number | null;
  row_count: number | null;
  /**
   * Rows this reader was not shown, on the row as well as inside the file.
   *
   * Present because a shortfall with no explanation is the defect every
   * drill-through in Phase 6 avoids — and an export is the one place a reader
   * cannot compare against the screen.
   */
  hidden_count: number | null;
  failure_reason: string | null;
  expires_at: string | null;
  created_at: string;
};
