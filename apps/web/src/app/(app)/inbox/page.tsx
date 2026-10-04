import Link from "next/link";
import { ButtonLink } from "@/components/ui/Button";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { EmptyState } from "@/components/ui/EmptyState";
import { MarkReadButton } from "@/features/inbox/MarkReadButton";
import { NotificationList } from "@/features/inbox/NotificationList";
import { ReviewQueue } from "@/features/inbox/ReviewQueue";
import type { Approval, Notification } from "@/features/work-item/types";
import { asLocale } from "@/i18n/config";
import { translator, type Translator } from "@/i18n/translate";
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

// Labels in the dictionaries as `inbox.tab.<key>` (ADR 0060).
const TABS = ["reviews", "waiting", "activity"] as const;

export default async function InboxPage({
  searchParams,
}: {
  searchParams: Promise<{ tab?: string; cursor?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  const tab = TABS.find((key) => key === params.tab) ?? "reviews";
  // Translated (ADR 0060). The notifications' own sentences are written by
  // the server and stay English until the API speaks the language too.
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // A page further along whichever queue is open. Only that tab's request
  // carries it: the other tab's count is a fact about its whole queue.
  const cursor = typeof params.cursor === "string" && params.cursor !== "" ? params.cursor : null;
  const after = (forTab: (typeof TABS)[number]) =>
    cursor !== null && tab === forTab ? `&cursor=${encodeURIComponent(cursor)}` : "";

  // Every list here is a PAGE, and every count beside it is a total the server
  // counted before paging. They are different questions and this screen used to
  // answer both with `array.length` — a number that stops at the page size and
  // never says so. `/notifications` has been cursor-paginated all along, so the
  // header already disagreed with the badge in the shell beside it, which reads
  // its count from the server (ADR 0008: a count is a fact about the queue, the
  // rows are a page of it).
  const [reviews, waiting, notifications, unread] = await Promise.all([
    api<Approval[]>(`/approvals?role=reviewer&status=pending${after("reviews")}`)
      .then((r) => ({ rows: r.data, total: totalIn(r.meta, r.data.length), next: nextIn(r.meta) }))
      .catch(() => ({ rows: [] as Approval[], total: 0, next: null })),
    api<Approval[]>(`/me/approvals?role=requester&status=pending${after("waiting")}`)
      .then((r) => ({ rows: r.data, total: totalIn(r.meta, r.data.length), next: nextIn(r.meta) }))
      .catch(() => ({ rows: [] as Approval[], total: 0, next: null })),
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
        title={t("nav.inbox")}
        description={
          reviews.total > 0
            ? `${t("inbox.summary.waiting", { count: reviews.total })} · ${t("inbox.summary.unread", { count: unread })}`
            : t("inbox.summary.unread", { count: unread })
        }
        // "All" is decided by the server, not by the rows this page happened to
        // page in: the badge counts every unread notification, so a control
        // that cleared only the visible ones would leave a number nobody could
        // get to zero — which is how the badge got into this state.
        action={
          unread > 0 ? (
            <MarkReadButton label={t("inbox.markAll")} busyLabel={t("inbox.marking")} variant="secondary" />
          ) : undefined
        }
      />

      <nav aria-label={t("inbox.sections")} className="flex gap-1 overflow-x-auto border-b border-n-100">
        {TABS.map((key) => {
          const active = key === tab;
          // The server's totals for the two queues. "Everything else" has no
          // total of its own — it is the notification page minus the approval
          // rows, a filter this screen applies — so it counts what it shows and
          // is the one tab whose number is honestly about the page.
          const count =
            key === "reviews" ? reviews.total
            : key === "waiting" ? waiting.total
            : rest.length;

          return (
            <Link
              key={key}
              href={`/inbox?tab=${key}`}
              aria-current={active ? "page" : undefined}
              className={clsx(
                "-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-body",
                active
                  ? "border-a-500 font-medium text-n-900"
                  : "border-transparent text-n-500 hover:text-n-900",
              )}
            >
              {t(`inbox.tab.${key}`)}
              {count > 0 && <span className="ml-1.5 tabular-nums text-n-500">{count}</span>}
            </Link>
          );
        })}
      </nav>

      <PageBody>
        {/* One panel per tab rather than a list floating under the tab strip.
            The strip already says which queue this is; the panel says where it
            ends, which is what a list with no bottom edge never does
            (ADR 0024). */}
        {tab === "reviews" && (
          reviews.rows.length === 0 ? (
            <EmptyState
              title={t("inbox.reviews.empty.title")}
              description={t("inbox.reviews.empty.body")}
            />
          ) : (
            <Panel
              id="reviews"
              title={t("inbox.reviews.title")}
              description={pageNote(t("inbox.reviews.description"), reviews, cursor !== null, t)}
              footer={<QueuePager tab="reviews" next={reviews.next} paged={cursor !== null} t={t} />}
              bleed
            >
              <ReviewQueue
                approvals={reviews.rows}
                timeZone={me.user.timezone}
                emptyLabel={t("inbox.reviews.emptyLabel")}
                locale={locale}
              />
            </Panel>
          )
        )}

        {tab === "waiting" && (
          waiting.rows.length === 0 ? (
            <EmptyState
              title={t("inbox.waiting.empty.title")}
              description={t("inbox.waiting.empty.body")}
            />
          ) : (
            <Panel
              id="waiting"
              title={t("inbox.waiting.title")}
              description={pageNote(t("inbox.waiting.description"), waiting, cursor !== null, t)}
              footer={<QueuePager tab="waiting" next={waiting.next} paged={cursor !== null} t={t} />}
              bleed
            >
              <ReviewQueue
                approvals={waiting.rows}
                timeZone={me.user.timezone}
                emptyLabel={t("inbox.waiting.emptyLabel")}
                locale={locale}
              />
            </Panel>
          )
        )}

        {tab === "activity" && (
          rest.length === 0 ? (
            <EmptyState
              title={t("inbox.activity.empty.title")}
              description={t("inbox.activity.empty.body")}
            />
          ) : (
            <Panel
              id="activity"
              title={t("inbox.activity.title")}
              description={t("inbox.activity.description")}
              bleed
            >
              <NotificationList notifications={rest} timeZone={me.user.timezone} locale={locale} />
            </Panel>
          )
        )}
      </PageBody>
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
/**
 * Where this page sits in the queue, when it is not the whole of it.
 *
 * The queue is oldest-first on purpose, and the API serves it a page at a
 * time. This page used to render the first page and stop, so a reviewer with
 * more than fifty pending never saw the newest ones at all — nothing said they
 * existed beyond a count in the tab.
 */
function pageNote(
  base: string,
  queue: { rows: unknown[]; total: number; next: string | null },
  paged: boolean,
  t: Translator,
): string {
  if (paged) return t("inbox.page.later", { shown: queue.rows.length, total: queue.total });
  if (queue.next !== null) {
    return `${base} ${t("inbox.page.partOf", { shown: queue.rows.length, total: queue.total })}`;
  }

  return base;
}

function QueuePager({
  tab,
  next,
  paged,
  t,
}: {
  tab: string;
  next: string | null;
  paged: boolean;
  t: Translator;
}) {
  if (next === null && !paged) return null;

  return (
    <div className="flex flex-wrap items-center gap-2">
      {paged && (
        <ButtonLink href={`/inbox?tab=${tab}`} size="sm" variant="ghost">
          {t("inbox.page.backToStart")}
        </ButtonLink>
      )}
      {next !== null && (
        <ButtonLink
          href={`/inbox?${new URLSearchParams({ tab, cursor: next })}`}
          size="sm"
          variant="secondary"
        >
          {t("browse.next")}
        </ButtonLink>
      )}
    </div>
  );
}

function nextIn(meta: unknown): string | null {
  const pagination = (meta as { pagination?: { next_cursor?: unknown } } | null)?.pagination;

  return typeof pagination?.next_cursor === "string" ? pagination.next_cursor : null;
}

function totalIn(meta: unknown, fallback: number): number {
  const total = (meta as { total?: unknown } | null)?.total;

  return typeof total === "number" ? total : fallback;
}
