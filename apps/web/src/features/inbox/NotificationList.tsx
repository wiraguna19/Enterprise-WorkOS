import Link from "next/link";
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
    <div className="space-y-6">
      {groups.map(([day, items]) => (
        <section key={day} aria-labelledby={`day-${day}`}>
          <h2
            id={`day-${day}`}
            className="mb-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
          >
            {day}
          </h2>

          <ul className="divide-y divide-n-100 border-y border-n-100">
            {items.map((notification) => (
              <li key={notification.id}>
                <Link
                  href={hrefFor(notification)}
                  className={clsx(
                    "flex items-baseline gap-3 py-2.5 pl-3 pr-2 hover:bg-n-50",
                    // Unread is marked by a rule on the leading edge, not by a
                    // bold row: bolding half an inbox makes the whole thing
                    // harder to read, which is the opposite of the point.
                    !notification.read && "border-l-2 border-l-a-500 pl-[10px]",
                  )}
                >
                  <span className="min-w-0 flex-1 text-body text-n-900">
                    {describe(notification)}
                  </span>

                  <time
                    dateTime={notification.created_at}
                    className="shrink-0 text-caption tabular-nums text-n-500"
                  >
                    {formatDateTime(notification.created_at, timeZone)}
                  </time>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

/**
 * One sentence per type, in the active voice, naming the person.
 *
 * "Approval requested" is a category label; "Sarah asked you to review ENG-142"
 * is something you can act on without opening it.
 *
 * Two cases are easy to get wrong and both showed up the first time this
 * rendered against the seeded rows:
 *
 *   - A notification with no actor was reading "Someone · work escalated". No
 *     actor means a RULE did it, not a mystery person, and the sentence should
 *     say what happened rather than invent an anonymous human.
 *   - Any type not listed fell through to the raw event key. The fallback is
 *     kept — a blank row is worse than an awkward one — but every type the
 *     system actually sends now has a sentence, and this list is the place to
 *     add one when a new type is introduced.
 */
function describe(notification: Notification): string {
  const actor = notification.actor?.name ?? notification.payload?.actor_name ?? null;
  const reference = notification.payload?.reference ?? "";
  const title = notification.payload?.title ?? "";
  const subject = reference ? `${reference} · ${title}` : title || "an item";

  // Rule-caused notifications. Impersonal on purpose: attributing an automated
  // escalation to a person makes people ask that person about it.
  if (actor === null) {
    switch (notification.type) {
      case "work.escalated":
        return `${subject} is overdue and has been escalated to you`;
      case "work.needs_assignee":
        return `${subject} is urgent and has nobody on it`;
      case "work.due_soon":
        return `${subject} is due soon`;
      default:
        return `${subject} · ${notification.type.replace(/[._]/g, " ")}`;
    }
  }

  switch (notification.type) {
    case "approval.requested":
      return `${actor} asked you to review ${subject}`;
    case "approval.approved":
      return `${actor} approved ${subject}`;
    case "approval.changes_requested":
      return `${actor} asked for changes on ${subject}`;
    case "approval.rejected":
      return `${actor} rejected ${subject}`;
    case "work.assigned":
      return `${actor} assigned you ${subject}`;
    case "work.reassigned_away":
      return `${actor} moved ${subject} to someone else`;
    case "work.completed":
      return `${actor} completed ${subject}`;
    case "work.blocked":
      return `${actor} marked ${subject} blocked`;
    case "comment.mentioned":
      return `${actor} mentioned you on ${subject}`;
    case "comment.replied":
      return `${actor} replied to you on ${subject}`;
    default:
      return `${actor} · ${notification.type.replace(/[._]/g, " ")} · ${subject}`;
  }
}

function hrefFor(notification: Notification): string {
  const reference = notification.payload?.reference;

  if (notification.type.startsWith("approval.")) {
    return reference ? `/work/${reference}` : "/inbox?tab=reviews";
  }

  return reference ? `/work/${reference}` : "/inbox";
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
