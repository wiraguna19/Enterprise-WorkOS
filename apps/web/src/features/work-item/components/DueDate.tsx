import { clsx } from "@/lib/clsx";

/**
 * Overdue turns the DATE red and bold — never the whole row (docs/09 §5).
 *
 * A board where entire cards go red is unreadable within a week, and the row
 * background is already carrying selection and hover state.
 *
 * The lateness label is computed in HOURS below a day. An earlier version
 * rendered "today · 0d late" for anything overdue by a few hours, which is
 * both contradictory and useless: "0d late" tells the reader nothing and
 * reads as a bug. Sub-day lateness is exactly the case a person can still
 * act on, so it gets the more precise unit.
 */
export function DueDate({
  value,
  overdue,
  timeZone,
}: {
  value: string | null;
  overdue: boolean;
  timeZone: string;
}) {
  if (!value) return <span className="text-caption text-n-500">—</span>;

  const date = new Date(value);
  const now = new Date();

  const sameYear = date.getFullYear() === now.getFullYear();
  const absolute = new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    ...(sameYear ? {} : { year: "numeric" }),
    timeZone,
  }).format(date);

  const time = new Intl.DateTimeFormat("en-GB", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
  }).format(date);

  // Calendar-day difference in the USER's timezone, not the server's: "due
  // tomorrow" must mean tomorrow where the reader is.
  const dayKey = (d: Date) =>
    new Intl.DateTimeFormat("en-CA", { timeZone, year: "numeric", month: "2-digit", day: "2-digit" })
      .format(d);

  const dayDelta = Math.round(
    (Date.parse(dayKey(date)) - Date.parse(dayKey(now))) / 86_400_000,
  );

  const label =
    dayDelta === 0 ? "today"
    : dayDelta === 1 ? "tomorrow"
    : dayDelta === -1 ? "yesterday"
    : absolute;

  return (
    <span
      className={clsx(
        "whitespace-nowrap text-caption tabular-nums",
        overdue ? "font-semibold text-s-danger" : "text-n-500",
      )}
      title={`Due ${absolute} at ${time}`}
    >
      {label}
      {overdue && <span className="ml-1">· {lateness(date, now)}</span>}
    </span>
  );
}

/**
 * How late, in the largest unit that still says something useful.
 *
 * Minutes below an hour, hours below a day, days below a month, then the bare
 * word — because "47d late" and "312d late" prompt the same reaction and the
 * precision is false comfort.
 */
function lateness(due: Date, now: Date): string {
  const minutes = Math.floor((now.getTime() - due.getTime()) / 60_000);

  if (minutes < 60) return `${Math.max(minutes, 1)}m late`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h late`;

  const days = Math.floor(hours / 24);
  if (days < 31) return `${days}d late`;

  return "long overdue";
}
