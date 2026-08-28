import Link from "next/link";
import { formatDate } from "@/lib/format";
import type { TimesheetDay, TimesheetMeta } from "./types";

/**
 * A week of logged time, by day (docs/08 §3).
 *
 * Days with nothing on them are not shown. A timesheet padded with empty rows
 * reads as a form to complete, and this is a record of what happened — the
 * product does not ask anyone to account for a day they did not log.
 *
 * Navigation is by week and lives in the URL, so a particular week is a link
 * someone can send.
 */
export function Timesheet({
  days,
  window,
  timeZone,
}: {
  days: TimesheetDay[];
  window: TimesheetMeta;
  timeZone: string;
}) {
  const shift = (weeks: number) => {
    const from = new Date(`${window.from}T00:00:00Z`);
    from.setUTCDate(from.getUTCDate() + weeks * 7);

    const to = new Date(from);
    to.setUTCDate(to.getUTCDate() + 6);

    return `/time?from=${from.toISOString().slice(0, 10)}&to=${to.toISOString().slice(0, 10)}`;
  };

  return (
    <div className="space-y-4">
      <nav className="flex items-center gap-4 text-body-sm">
        <Link href={shift(-1)} className="text-a-700 hover:underline">
          ← Previous
        </Link>
        <span className="text-n-500">
          {formatDate(window.from, timeZone)} – {formatDate(window.to, timeZone)}
        </span>
        <Link href={shift(1)} className="text-a-700 hover:underline">
          Next →
        </Link>
      </nav>

      <div className="space-y-5">
        {days.map((day) => (
          <section key={day.date}>
            <h2 className="flex items-baseline justify-between border-b border-n-100 pb-1">
              <span className="text-body-sm font-medium text-n-900">
                {formatDate(day.date, timeZone)}
              </span>
              <span className="tabular-nums text-body-sm text-n-700">{day.hours} h</span>
            </h2>

            <ul className="divide-y divide-n-100">
              {day.entries.map((entry) => (
                <li key={entry.id} className="flex items-baseline gap-3 py-1.5 text-body-sm">
                  <span className="w-14 shrink-0 tabular-nums text-n-900">{entry.hours} h</span>

                  {entry.work_item ? (
                    <Link
                      href={`/work/${entry.work_item.reference}`}
                      className="flex min-w-0 flex-1 items-baseline gap-2 hover:text-a-700"
                    >
                      <span className="shrink-0 font-mono text-caption text-n-500">
                        {entry.work_item.reference}
                      </span>
                      <span className="truncate">{entry.work_item.title}</span>
                    </Link>
                  ) : (
                    // The work item was deleted; the hours were still worked,
                    // and dropping the row would quietly change a total someone
                    // may have already reported.
                    <span className="min-w-0 flex-1 text-n-400">deleted work item</span>
                  )}

                  {entry.note && (
                    <span className="hidden min-w-0 max-w-[40%] truncate text-caption text-n-500 sm:block">
                      {entry.note}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </div>
  );
}
