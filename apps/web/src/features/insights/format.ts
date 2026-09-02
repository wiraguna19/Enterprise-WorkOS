/**
 * Cycle time, printed.
 *
 * Hours below two days, days above: "62 h" is a number the reader has to divide
 * before it means anything, and the dividing is where they stop reading. Shared
 * rather than repeated, so the figure in the headline, the figure in the weekly
 * table and the figure on the drill-through cannot drift into three formats of
 * the same duration.
 */
export function formatCycleHours(value: number | null): string {
  if (value === null) return "—";
  if (value < 48) return `${Math.round(value)} h`;

  return `${(value / 24).toFixed(1)} d`;
}

/**
 * The window a weekly row covers, as the completions endpoint wants it.
 *
 * The row is grouped by `startOfWeek` on the API side, so the drill-through
 * window is that Monday through the Sunday after it — six days, not seven. An
 * off-by-one here shows up as a drill-through whose count does not match the
 * row that opened it, which is precisely the failure docs/10 is guarding
 * against.
 */
export function weekEnd(weekStart: string): string {
  const end = new Date(`${weekStart}T00:00:00Z`);
  end.setUTCDate(end.getUTCDate() + 6);

  return end.toISOString().slice(0, 10);
}
