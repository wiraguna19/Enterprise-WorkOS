import Link from "next/link";
import { formatDate } from "@/lib/format";
import type { CalendarEvent } from "./types";

/**
 * The month as a list, for phones (docs/08 §6).
 *
 * Not the grid made smaller. A month grid at 375px gives each day about 50
 * pixels, which fits a date and nothing else — so the calendar becomes a
 * picture of a calendar, and answering "what is due this week" means tapping
 * seven times. On a phone the question is "what is coming", and a list answers
 * it directly.
 *
 * Days with nothing on them are omitted. Days already past are not: the month
 * being viewed is the month that is shown, and hiding its first half would make
 * the previous/next controls navigate to something other than what they name.
 */
export function Agenda({
  events,
  timeZone,
}: {
  events: CalendarEvent[];
  timeZone: string;
}) {
  const day = (event: CalendarEvent) =>
    event.all_day
      ? event.starts_at.slice(0, 10)
      : new Intl.DateTimeFormat("en-CA", { timeZone, dateStyle: "short" }).format(
          new Date(event.starts_at),
        );

  const today = new Intl.DateTimeFormat("en-CA", { timeZone, dateStyle: "short" }).format(
    new Date(),
  );

  const byDay = new Map<string, CalendarEvent[]>();

  for (const event of events) {
    const key = day(event);

    byDay.set(key, [...(byDay.get(key) ?? []), event]);
  }

  const days = [...byDay.entries()].sort(([a], [b]) => a.localeCompare(b));

  if (days.length === 0) {
    return <p className="text-body-sm text-n-400">Nothing dated this month.</p>;
  }

  return (
    <div className="space-y-4">
      {days.map(([date, dayEvents]) => (
        <section key={date}>
          <h3 className="border-b border-n-100 pb-1 text-body-sm font-medium text-n-900">
            {formatDate(date, timeZone)}
            {date === today && <span className="ml-1.5 text-caption text-a-700">today</span>}
          </h3>

          <ul className="divide-y divide-n-100">
            {dayEvents.map((event) => (
              <li key={event.id} className="py-2">
                {event.is_projected ? (
                  // Nothing to open: the work item does not exist yet.
                  <span className="flex items-baseline gap-2 text-body-sm text-n-500">
                    <span className="truncate">{event.title}</span>
                    <span className="ml-auto shrink-0 text-caption">recurring</span>
                  </span>
                ) : event.type === "milestone" ? (
                  <span className="flex items-baseline gap-2 text-body-sm text-n-700">
                    <span className="truncate">{event.title}</span>
                    <span className="ml-auto shrink-0 text-caption text-n-500">milestone</span>
                  </span>
                ) : (
                  <Link
                    href={`/work/${event.reference}`}
                    className="flex items-baseline gap-2 text-body-sm text-n-900"
                  >
                    <span className="shrink-0 font-mono text-caption text-n-500">
                      {event.reference}
                    </span>
                    <span className="truncate">{event.title}</span>
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
