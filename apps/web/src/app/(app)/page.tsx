import { Badge } from "@/components/ui/Badge";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import { AtRiskList } from "@/features/insights/AtRiskList";
import { WorkloadPanel } from "@/features/people/WorkloadPanel";
import { TeamCapacity } from "@/features/insights/TeamCapacity";
import type { AtRiskItem } from "@/features/insights/types";
import type { Workload } from "@/features/people/types";
import type { Approval, WorkItem } from "@/features/work-item/types";
import { asLocale, INTL_TAG } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator, type Translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

type Attention = { unaccepted: WorkItem[]; overdue: WorkItem[] };

/**
 * Home (docs/08 §3).
 *
 * The ORDERING is the design, and it is the same one My Work uses: exceptions
 * first, then what is due today. Home is the shorter version — the first few of
 * each, and a way through to the full list — because a home screen that repeats
 * a whole page is a page nobody scrolls twice.
 *
 * Deliberately NOT a grid of number cards: no manager has ever made a decision
 * from "Total tasks: 847". The numbers that do appear are in the sentence under
 * the greeting, where they say what today looks like rather than sitting in
 * boxes.
 *
 * Role-adaptive means adaptive to the DATA (ADR 0009). The manager half appears
 * because the risk query returned rows or the reader has reports — not because
 * a role is called "manager", which is a per-organization string a customer can
 * rename. Everyone's own work comes first either way: a manager opening this
 * page still has their own overdue item.
 *
 * The personal half follows docs/08 §3's ordering exactly, and the ordering IS
 * the design: exceptions first, then today's commitments, then the week ahead,
 * then what is blocked on somebody else. "Waiting on others" earns its place
 * because half of a person's frustration is work they cannot progress, and no
 * todo list ever shows it.
 *
 * Composed here rather than by one endpoint because approvals belong to a
 * module Insights must not import (docs/04 §3) — the composition is the price
 * of the seam, and it is one server render either way.
 */
export default async function HomePage() {
  const me = await requireUser();
  const firstName = me.user.name.split(" ")[0];
  // Translated (ADR 0060): every word on this screen, and every shared
  // component on it is handed the locale.
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // Each read falls back to empty rather than failing the page: Home is the
  // first screen after sign-in, and one unavailable section is not a reason to
  // show somebody an error instead of their work.
  const [attention, today, upcoming, waiting, workload, counts, atRisk, capacity, reviews] =
    await Promise.all([
      api<Attention>("/me/work/needs-attention")
        .then((r) => r.data)
        .catch(() => ({ unaccepted: [], overdue: [] })),
      api<WorkItem[]>("/me/work?view=today&limit=5")
        .then((r) => r.data)
        .catch(() => [] as WorkItem[]),
      api<WorkItem[]>("/me/work?view=upcoming&limit=5")
        .then((r) => r.data)
        .catch(() => [] as WorkItem[]),
      api<WorkItem[]>("/me/work?view=waiting_on_others&limit=5")
        .then((r) => r.data)
        .catch(() => [] as WorkItem[]),
      // The same figure the person's own profile shows, from the same endpoint —
      // a second computation of "your week" would eventually disagree with it.
      api<Workload>(`/people/${me.membership.id}/workload`)
        .then((r) => r.data)
        .catch(() => null),
      api<Record<string, number>>("/me/work/counts")
        .then((r) => r.data)
        .catch(() => ({}) as Record<string, number>),
      api<AtRiskItem[]>("/insights/at-risk")
        .then((r) => r.data)
        .catch(() => [] as AtRiskItem[]),
      api<Array<Workload & { name: string | null }>>("/insights/my-reports/workload")
        .then((r) => ({ rows: r.data, withheld: Number(r.meta?.withheld_count ?? 0) }))
        .catch(() => ({ rows: [] as Array<Workload & { name: string | null }>, withheld: 0 })),
      api<Approval[]>("/approvals?role=reviewer&status=pending")
        .then((r) => r.data)
        .catch(() => [] as Approval[]),
    ]);

  // Overdue work is already an exception; anything that is ALSO overdue should
  // be named once, in the more urgent list.
  //
  // This rule was written for the unaccepted list and applied only there, while
  // "Due today" is `due_at < end of today` — which is every overdue item by
  // definition. So every late item appeared twice on this screen, in two
  // sections, one of them describing it wrongly: work that was due last Tuesday
  // is not due today.
  //
  // Deduplicated HERE rather than by narrowing the query, because
  // `/me/work?view=today` answers "what must be done by the end of today", and
  // late work belongs in that answer. Two readers, two right answers; the
  // screen is the one that has to choose.
  const overdueIds = new Set(attention.overdue.map((item) => item.id));
  const unaccepted = attention.unaccepted.filter((item) => !overdueIds.has(item.id));
  const dueToday = today.filter((item) => !overdueIds.has(item.id));

  // The manager half is shown because there is something to manage — someone
  // with reports but a quiet week still gets the capacity panel, someone with
  // neither gets the personal page they had before (ADR 0009). It no longer
  // needs a `managing` flag: each panel guards itself, and one condition kept
  // in two places is one that eventually disagrees with itself.

  const nothingToShow =
    attention.overdue.length === 0 &&
    unaccepted.length === 0 &&
    // The deduplicated list, like the section below renders: `today` alone
    // would keep the empty state away on a screen whose every section is empty.
    dueToday.length === 0 &&
    upcoming.length === 0 &&
    waiting.length === 0;

  return (
    <div className="space-y-4">
      <PageHeader
        title={t(greeting(me.user.timezone), { name: firstName })}
        description={summary(counts, me.user.timezone, t)}
      />

      <PageBody
        aside={
          <>
            {workload && (
              <Panel id="your-week" title={t("home.yourWeek")}>
                <WorkloadPanel workload={workload} locale={locale} />
              </Panel>
            )}

            {reviews.length > 0 && (
              // A count and a way through, rather than the queue itself: the
              // Inbox already renders that queue, and two implementations of
              // one list is how they start disagreeing about what is pending.
              <Panel
                id="waiting-review"
                title={t("home.waitingReview")}
                actions={<Badge tone="warning">{reviews.length}</Badge>}
              >
                <ButtonLink href="/inbox" variant="secondary" size="sm">
                  {t("home.openInbox")}
                </ButtonLink>
              </Panel>
            )}

            {capacity.rows.length > 0 && (
              <Panel id="capacity" title={t("home.reports")} bleed>
                <div className="px-4 py-3">
                  <TeamCapacity rows={capacity.rows} withheld={capacity.withheld} locale={locale} />
                </div>
              </Panel>
            )}
          </>
        }
      >
        {nothingToShow ? (
        <EmptyState
          title={t((counts.open ?? 0) > 0 ? "home.empty.quiet.title" : "home.empty.new.title")}
          description={t((counts.open ?? 0) > 0 ? "home.empty.quiet.body" : "home.empty.new.body")}
          action={
            me.permissions.includes("team.create") ? (
              <ButtonLink href="/teams" variant="primary">
                {t("home.browseTeams")}
              </ButtonLink>
            ) : undefined
          }
        />
        ) : (
          <>
            <Section
            id="overdue"
            title={t("home.section.overdue")}
            items={attention.overdue}
            timeZone={me.user.timezone}
            href="/my-work?view=overdue"
            t={t}
          />

          <Section
            id="assigned-not-yet-accepted"
            title={t("home.section.unaccepted")}
            items={unaccepted}
            timeZone={me.user.timezone}
            href="/my-work?view=assigned"
            t={t}
          />

          <Section
            id="due-today"
            title={t("home.section.dueToday")}
            items={dueToday}
            timeZone={me.user.timezone}
            href="/my-work?view=today"
            t={t}
          />

          <Section
            id="this-week"
            title={t("home.section.thisWeek")}
            items={upcoming}
            timeZone={me.user.timezone}
            href="/my-work?view=upcoming"
            t={t}
          />

          <Section
            id="waiting-on-others"
            title={t("home.section.waiting")}
            items={waiting}
            timeZone={me.user.timezone}
            href="/my-work?view=waiting_on_others"
            note={t("home.section.waitingNote")}
            t={t}
          />
        </>
      )}

        {atRisk.length > 0 && (
          <Panel
            id="at-risk"
            title={t("home.risk.title")}
            description={t("home.risk.description")}
            actions={<Badge tone="warning">{atRisk.length}</Badge>}
            bleed
          >
            <div className="px-4 py-3">
              <AtRiskList items={atRisk} timeZone={me.user.timezone} locale={locale} />
            </div>
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

function Section({
  id,
  title,
  items,
  timeZone,
  href,
  note,
  t,
}: {
  /**
   * The panel's id, given rather than derived from the title: the title is
   * now in the reader's language, and an id must not change with it.
   */
  id: string;
  title: string;
  items: WorkItem[];
  timeZone: string;
  href: string;
  /** One line saying what this section means, where the title does not say it. */
  note?: string;
  t: Translator;
}) {
  // An empty section is not rendered at all. A heading over nothing is a hole
  // in the page that the reader has to work out is not an error.
  if (items.length === 0) return null;

  return (
    <Panel
      id={`home-${id}`}
      title={title}
      description={note}
      actions={
        <ButtonLink href={href} variant="ghost" size="sm">
          {t("home.seeAll")}
        </ButtonLink>
      }
      bleed
    >
      <div className="divide-y divide-n-100">
        {items.slice(0, 5).map((item) => (
          <WorkItemRow key={item.id} item={item} timeZone={timeZone} locale={t.locale} />
        ))}
      </div>
    </Panel>
  );
}

/**
 * What today looks like, as a sentence.
 *
 * Overdue leads when there is any, because it is the only one of these numbers
 * that describes something already going wrong.
 */
function summary(counts: Record<string, number>, timeZone: string, t: Translator): string {
  const date = new Intl.DateTimeFormat(INTL_TAG[t.locale], {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone,
  }).format(new Date());

  const parts: string[] = [];

  if ((counts.overdue ?? 0) > 0) parts.push(t("count.overdue", { count: counts.overdue }));
  if ((counts.due_today ?? 0) > 0) parts.push(t("count.dueToday", { count: counts.due_today }));
  if (parts.length === 0 && (counts.open ?? 0) > 0) parts.push(t("count.open", { count: counts.open }));

  return parts.length === 0 ? date : `${date} · ${parts.join(" · ")}`;
}

/**
 * Morning, afternoon, or evening — in the reader's timezone, not the server's.
 *
 * A greeting is a small thing to get wrong and a conspicuous one: "Good
 * morning" at 9pm is the product telling someone it does not know where they
 * are (docs/07 §1).
 */
function greeting(timeZone: string): MessageKey {
  const hour = Number(
    new Intl.DateTimeFormat("en-GB", { hour: "numeric", hour12: false, timeZone }).format(
      new Date(),
    ),
  );

  if (hour < 12) return "home.greeting.morning";
  if (hour < 18) return "home.greeting.afternoon";

  return "home.greeting.evening";
}
