import Link from "next/link";
import { clsx } from "@/lib/clsx";
import type { CalendarEvent } from "./types";

/**
 * A month, as a grid (docs/08 §5).
 *
 * Weeks start on Monday, because the work week does. Days from the neighbouring
 * months are rendered rather than left blank so the grid keeps its shape, but
 * they are recessive: they are context, not this month.
 *
 * Each cell shows at most three events and then a count. A cell that grows to
 * fit its contents makes every other row taller for one busy Tuesday, and a
 * calendar whose rows jump around is one nobody can scan.
 */
const MAX_PER_DAY = 3;

export function MonthGrid({
  month,
  events,
  timeZone,
}: {
  /** Any date inside the month to render, as YYYY-MM-DD. */
  month: string;
  events: CalendarEvent[];
  timeZone: string;
}) {
  const anchor = new Date(`${month}T00:00:00Z`);
  const firstOfMonth = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth(), 1));

  // Monday-based offset: getUTCDay() is 0 for Sunday, which would put Sunday
  // first and shift every week by a day.
  const leading = (firstOfMonth.getUTCDay() + 6) % 7;

  const start = new Date(firstOfMonth);
  start.setUTCDate(start.getUTCDate() - leading);

  const byDay = new Map<string, CalendarEvent[]>();

  for (const event of events) {
    // Grouped in the viewer's zone, not the server's: an item due 23:00 in
    // Jakarta is a Tuesday deadline for the person in Jakarta, whatever UTC
    // says. All-day events carry no time and must not be shifted at all.
    const key = event.all_day
      ? event.starts_at.slice(0, 10)
      : new Intl.DateTimeFormat("en-CA", { timeZone, dateStyle: "short" }).format(
          new Date(event.starts_at),
        );

    byDay.set(key, [...(byDay.get(key) ?? []), event]);
  }

  const today = new Intl.DateTimeFormat("en-CA", { timeZone, dateStyle: "short" }).format(
    new Date(),
  );

  const cells = Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start);
    date.setUTCDate(date.getUTCDate() + index);

    return date;
  });

  return (
    <div className="overflow-x-auto">
      <div className="min-w-[44rem]">
        <div className="grid grid-cols-7 border-b border-n-200">
          {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((day) => (
            <div
              key={day}
              className="px-2 py-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
            >
              {day}
            </div>
          ))}
        </div>

        <div className="grid grid-cols-7">
          {cells.map((date) => {
            const key = date.toISOString().slice(0, 10);
            const dayEvents = byDay.get(key) ?? [];
            const outside = date.getUTCMonth() !== firstOfMonth.getUTCMonth();

            return (
              <div
                key={key}
                className={clsx(
                  "min-h-24 border-b border-r border-n-100 p-1",
                  outside && "bg-n-25",
                )}
              >
                <div
                  className={clsx(
                    "mb-0.5 text-caption",
                    key === today
                      ? "font-semibold text-a-700"
                      : outside
                        ? "text-n-300"
                        : "text-n-500",
                  )}
                >
                  {date.getUTCDate()}
                </div>

                <ul className="space-y-0.5">
                  {dayEvents.slice(0, MAX_PER_DAY).map((event) => (
                    <li key={event.id}>
                      <EventChip event={event} />
                    </li>
                  ))}
                </ul>

                {dayEvents.length > MAX_PER_DAY && (
                  <div className="mt-0.5 text-micro text-n-500">
                    +{dayEvents.length - MAX_PER_DAY} more
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/**
 * A projected occurrence is drawn as an outline, never filled.
 *
 * "This will appear on Monday" and "this exists and is due Monday" are
 * different facts, and a calendar that draws them identically teaches people to
 * distrust all of it (docs/10, Phase 5). It is also not a link: there is
 * nothing to open, because the work item does not exist yet.
 */
function EventChip({ event }: { event: CalendarEvent }) {
  const label = (
    <span className="block truncate">
      {event.reference && <span className="font-mono text-micro">{event.reference} </span>}
      {event.title}
    </span>
  );

  if (event.is_projected) {
    return (
      <span
        title={`${event.title} — recurring, not created yet`}
        className="block rounded-sm border border-dashed border-n-300 px-1 text-micro text-n-500"
      >
        {label}
      </span>
    );
  }

  if (event.type === "milestone") {
    return (
      <span className="block rounded-sm bg-s-active/10 px-1 text-micro text-n-700">{label}</span>
    );
  }

  return (
    <Link
      href={`/work/${event.reference}`}
      className="block rounded-sm bg-a-50 px-1 text-micro text-n-700 hover:bg-a-50 hover:text-a-700"
    >
      {label}
    </Link>
  );
}
