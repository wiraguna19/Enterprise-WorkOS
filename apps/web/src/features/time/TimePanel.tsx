import { LogTimeForm } from "./LogTimeForm";
import type { TimeEntry } from "./types";

/**
 * Time on a work item: the entries, the total, and the way to add one
 * (docs/03 §4, docs/08 §4).
 *
 * Both totals are shown when they disagree. `actual_hours_cache` is derived and
 * recalculated inside the same transaction as every write (ADR 0003), so it
 * should equal the sum of the rows above it — and on the one day it does not,
 * the person looking at the number is the one who can say so. Hiding the
 * disagreement would turn a visible bug into a silent one.
 */
export function TimePanel({
  reference,
  entries,
  total,
  cachedTotal,
  canLog,
}: {
  reference: string;
  entries: TimeEntry[];
  total: number;
  cachedTotal: number;
  canLog: boolean;
}) {
  const drifted = Math.abs(total - cachedTotal) > 0.005;

  return (
    <div className="space-y-3">
      <div className="flex items-baseline justify-between">
        <span className="text-body-sm text-n-700">
          {total > 0 ? `${total} h logged` : "No time logged"}
        </span>

        {drifted && (
          <span className="text-caption text-s-danger">
            rollup says {cachedTotal} h — these should match
          </span>
        )}
      </div>

      {entries.length > 0 && (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {entries.map((entry) => (
            <li key={entry.id} className="flex items-baseline gap-3 py-1.5 text-body-sm">
              <span className="w-14 shrink-0 tabular-nums text-n-900">{entry.hours} h</span>
              <span className="w-24 shrink-0 text-caption text-n-500">{entry.logged_on}</span>
              <span className="min-w-0 flex-1 truncate text-n-700">
                {entry.note || <span className="text-n-400">—</span>}
              </span>
              <span className="shrink-0 text-caption text-n-500">{entry.person}</span>
            </li>
          ))}
        </ul>
      )}

      {/* The API decides; this only renders what it already decided
          (docs/05 §3). */}
      {canLog && <LogTimeForm reference={reference} />}
    </div>
  );
}
