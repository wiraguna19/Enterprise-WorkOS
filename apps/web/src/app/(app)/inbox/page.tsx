import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { MarkReadButton } from "@/features/inbox/MarkReadButton";
import { NotificationList } from "@/features/inbox/NotificationList";
import { ReviewQueue } from "@/features/inbox/ReviewQueue";
import type { Approval, Notification } from "@/features/work-item/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { clsx } from "@/lib/clsx";

/**
 * The Inbox (docs/08 §7).
 *
 * Three tabs, in the order a person needs them: what someone is waiting on ME
 * for, what I am waiting on someone else for, and everything else that
 * happened. Decisions come first because a pending approval blocks another
 * person's day, while a notification about a comment does not.
 *
 * The third tab is deliberately last and deliberately quiet. An inbox whose
 * loudest content is "someone edited a field" is one people stop opening.
 */

const TABS = [
  { key: "reviews", label: "Needs your decision" },
  { key: "waiting", label: "Waiting on others" },
  { key: "activity", label: "Everything else" },
] as const;

export default async function InboxPage({
  searchParams,
}: {
  searchParams: Promise<{ tab?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  const tab = TABS.find((t) => t.key === params.tab)?.key ?? "reviews";

  // Every list here is a PAGE, and every count beside it is a total the server
  // counted before paging. They are different questions and this screen used to
  // answer both with `array.length` — a number that stops at the page size and
  // never says so. `/notifications` has been cursor-paginated all along, so the
  // header already disagreed with the badge in the shell beside it, which reads
  // its count from the server (ADR 0008: a count is a fact about the queue, the
  // rows are a page of it).
  const [reviews, waiting, notifications, unread] = await Promise.all([
    api<Approval[]>("/approvals?role=reviewer&status=pending")
      .then((r) => ({ rows: r.data, total: totalIn(r.meta, r.data.length) }))
      .catch(() => ({ rows: [] as Approval[], total: 0 })),
    api<Approval[]>("/me/approvals?role=requester&status=pending")
      .then((r) => ({ rows: r.data, total: totalIn(r.meta, r.data.length) }))
      .catch(() => ({ rows: [] as Approval[], total: 0 })),
    api<Notification[]>("/notifications")
      .then((r) => r.data).catch(() => [] as Notification[]),
    api<{ unread: number }>("/notifications/unread-count")
      .then((r) => r.data.unread).catch(() => 0),
  ]);

  // Notifications about approvals already have their own tab; repeating them
  // under "everything else" is the duplication that makes an inbox feel noisy.
  const rest = notifications.filter((n) => !n.type.startsWith("approval."));

  return (
    <div className="space-y-5">
      <PageHeader
        title="Inbox"
        description={
          reviews.total > 0
            ? `${reviews.total} waiting on you · ${unread} unread`
            : `${unread} unread`
        }
        // "All" is decided by the server, not by the rows this page happened to
        // page in: the badge counts every unread notification, so a control
        // that cleared only the visible ones would leave a number nobody could
        // get to zero — which is how the badge got into this state.
        action={
          unread > 0 ? (
            <MarkReadButton label="Mark all read" busyLabel="Marking…" variant="secondary" />
          ) : undefined
        }
      />

      <nav aria-label="Inbox sections" className="flex gap-1 overflow-x-auto border-b border-n-100">
        {TABS.map((t) => {
          const active = t.key === tab;
          // The server's totals for the two queues. "Everything else" has no
          // total of its own — it is the notification page minus the approval
          // rows, a filter this screen applies — so it counts what it shows and
          // is the one tab whose number is honestly about the page.
          const count =
            t.key === "reviews" ? reviews.total
            : t.key === "waiting" ? waiting.total
            : rest.length;

          return (
            <Link
              key={t.key}
              href={`/inbox?tab=${t.key}`}
              aria-current={active ? "page" : undefined}
              className={clsx(
                "-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-body",
                active
                  ? "border-a-500 font-medium text-n-900"
                  : "border-transparent text-n-500 hover:text-n-900",
              )}
            >
              {t.label}
              {count > 0 && <span className="ml-1.5 tabular-nums text-n-500">{count}</span>}
            </Link>
          );
        })}
      </nav>

      {tab === "reviews" && (
        reviews.rows.length === 0 ? (
          <EmptyState
            title="Nothing is waiting on you"
            description="When someone submits work for your review it appears here, with their note, so you can decide without opening every item."
          />
        ) : (
          <ReviewQueue
            approvals={reviews.rows}
            timeZone={me.user.timezone}
            emptyLabel="Nothing is waiting on you."
          />
        )
      )}

      {tab === "waiting" && (
        waiting.rows.length === 0 ? (
          <EmptyState
            title="You are not waiting on anyone"
            description="Work you submit for review stays here until it is decided, so a submission never disappears the moment you send it."
          />
        ) : (
          <ReviewQueue
            approvals={waiting.rows}
            timeZone={me.user.timezone}
            emptyLabel="You are not waiting on anyone."
          />
        )
      )}

      {tab === "activity" && (
        rest.length === 0 ? (
          <EmptyState
            title="Nothing else to catch up on"
            description="Assignments, mentions, and escalations land here. Everything you do yourself is left out on purpose."
          />
        ) : (
          <NotificationList notifications={rest} timeZone={me.user.timezone} />
        )
      )}
    </div>
  );
}

/**
 * The `total` the API counted before paging, or the page's own length.
 *
 * The fallback is for an endpoint that has not been paginated yet rather than
 * for a missing field: guessing zero would hide a queue, and guessing the page
 * length is exactly what this function exists to stop — but only where a total
 * was never sent.
 */
function totalIn(meta: unknown, fallback: number): number {
  const total = (meta as { total?: unknown } | null)?.total;

  return typeof total === "number" ? total : fallback;
}
