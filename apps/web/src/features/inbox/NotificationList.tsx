import Link from "next/link";
import { MarkReadButton } from "./MarkReadButton";
import { clsx } from "@/lib/clsx";
import { formatDateTime } from "@/lib/format";
import type { Notification } from "@/features/work-item/types";

/**
 * The inbox list (docs/08 §7).
 *
 * Two decisions here matter more than the layout:
 *
 *   1. Each row renders from the notification's own PAYLOAD, not from a join to
 *      the subject. The payload is a snapshot taken at send time, so "Ahmad
 *      requested your review of ENG-142" still reads correctly after the item
 *      is renamed, and does not become a blank row if it is deleted.
 *
 *   2. Every row is a link to the thing itself. A notification you cannot act
 *      on from is a notification that trains people to clear the badge without
 *      reading it.
 *
 * Grouped by day, because "when" is how people actually scan an inbox — and
 * ungrouped reverse-chronological lists all look the same length regardless of
 * how much is in them.
 */
export function NotificationList({
  notifications,
  timeZone,
}: {
  notifications: Notification[];
  timeZone?: string;
}) {
  const groups = groupByDay(notifications, timeZone);

  return (
    // Inside a panel now (ADR 0024), so the day headings carry the gutter the
    // container gave up and sit on their own band — a date floating flush
    // against rows above and below it reads as a row rather than as a break.
    <div>
      {groups.map(([day, items]) => (
        <section key={day} aria-labelledby={`day-${day}`}>
          <h2
            id={`day-${day}`}
            className="border-b border-n-100 bg-n-25 px-4 py-1.5 text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
          >
            {day}
          </h2>

          <ul className="divide-y divide-n-100">
            {items.map((notification) => (
              <li
                key={notification.id}
                className={clsx(
                  "flex items-baseline gap-1 pr-2 hover:bg-n-50",
                  // Unread is marked by a rule on the leading edge, not by a
                  // bold row: bolding half an inbox makes the whole thing
                  // harder to read, which is the opposite of the point.
                  !notification.read && "border-l-2 border-l-a-500",
                )}
              >
                <Link
                  href={hrefFor(notification)}
                  className={clsx(
                    "flex min-w-0 flex-1 items-baseline gap-3 py-2.5 pl-3",
                    !notification.read && "pl-[10px]",
                  )}
                >
                  <span className="min-w-0 flex-1 text-body text-n-900">
                    {notification.message}
                    {notification.subject.title !== null && (
                      <span className="text-n-500"> · {notification.subject.title}</span>
                    )}
                  </span>

                  <time
                    dateTime={notification.created_at}
                    className="shrink-0 text-caption tabular-nums text-n-500"
                  >
                    {formatDateTime(notification.created_at, timeZone)}
                  </time>
                </Link>

                {/* Only unread rows carry it: a control that does nothing on
                    two-thirds of the rows is noise, and a disabled one is
                    worse. Opening the item is not what clears it — a row you
                    read from the list without clicking through is still read,
                    and marking on navigation would clear things nobody saw. */}
                {!notification.read && (
                  <MarkReadButton
                    ids={[notification.id]}
                    label="Mark read"
                    busyLabel="Marking…"
                  />
                )}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

/**
 * Every row goes to the thing it is about.
 *
 * The reference comes from the SUBJECT block the API sends, not from a payload
 * the API does not. Reading the wrong field meant every row fell through to
 * "/inbox" — a list whose every entry linked back to itself, which reads as a
 * broken product rather than a missing one.
 */
function hrefFor(notification: Notification): string {
  const reference = notification.subject.reference;

  if (notification.type.startsWith("approval.")) {
    return reference === null ? "/inbox?tab=reviews" : `/work/${reference}`;
  }

  return reference === null ? "/inbox" : `/work/${reference}`;
}

function groupByDay(
  notifications: Notification[],
  timeZone?: string,
): Array<[string, Notification[]]> {
  const today = new Intl.DateTimeFormat("en-GB", { dateStyle: "full", timeZone })
    .format(new Date());

  const groups = new Map<string, Notification[]>();

  for (const notification of notifications) {
    const full = new Intl.DateTimeFormat("en-GB", { dateStyle: "full", timeZone })
      .format(new Date(notification.created_at));

    const label = full === today
      ? "Today"
      : new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", timeZone })
          .format(new Date(notification.created_at));

    groups.set(label, [...(groups.get(label) ?? []), notification]);
  }

  return [...groups.entries()];
}
