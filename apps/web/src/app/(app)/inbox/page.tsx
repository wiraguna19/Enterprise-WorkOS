import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
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

  const [reviews, waiting, notifications] = await Promise.all([
    api<Approval[]>("/approvals?role=reviewer&status=pending")
      .then((r) => r.data).catch(() => [] as Approval[]),
    api<Approval[]>("/me/approvals?role=requester&status=pending")
      .then((r) => r.data).catch(() => [] as Approval[]),
    api<Notification[]>("/notifications")
      .then((r) => r.data).catch(() => [] as Notification[]),
  ]);

  // Notifications about approvals already have their own tab; repeating them
  // under "everything else" is the duplication that makes an inbox feel noisy.
  const rest = notifications.filter((n) => !n.type.startsWith("approval."));
  const unread = notifications.filter((n) => !n.read).length;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Inbox"
        description={
          reviews.length > 0
            ? `${reviews.length} waiting on you · ${unread} unread`
            : `${unread} unread`
        }
      />

      <nav aria-label="Inbox sections" className="flex gap-1 overflow-x-auto border-b border-n-100">
        {TABS.map((t) => {
          const active = t.key === tab;
          const count =
            t.key === "reviews" ? reviews.length
            : t.key === "waiting" ? waiting.length
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
        reviews.length === 0 ? (
          <EmptyState
            title="Nothing is waiting on you"
            description="When someone submits work for your review it appears here, with their note, so you can decide without opening every item."
          />
        ) : (
          <ReviewQueue
            approvals={reviews}
            timeZone={me.user.timezone}
            emptyLabel="Nothing is waiting on you."
          />
        )
      )}

      {tab === "waiting" && (
        waiting.length === 0 ? (
          <EmptyState
            title="You are not waiting on anyone"
            description="Work you submit for review stays here until it is decided, so a submission never disappears the moment you send it."
          />
        ) : (
          <ReviewQueue
            approvals={waiting}
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
