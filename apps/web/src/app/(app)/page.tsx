import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { Button } from "@/components/ui/Button";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
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
 */
export default async function HomePage() {
  const me = await requireUser();
  const firstName = me.user.name.split(" ")[0];

  const [attention, today, counts] = await Promise.all([
    api<Attention>("/me/work/needs-attention")
      .then((r) => r.data)
      .catch(() => ({ unaccepted: [], overdue: [] })),
    api<WorkItem[]>("/me/work?view=today&limit=5")
      .then((r) => r.data)
      .catch(() => [] as WorkItem[]),
    api<Record<string, number>>("/me/work/counts")
      .then((r) => r.data)
      .catch(() => ({}) as Record<string, number>),
  ]);

  // Overdue work is already an exception; an unaccepted assignment that is also
  // overdue should be named once, in the more urgent list.
  const overdueIds = new Set(attention.overdue.map((item) => item.id));
  const unaccepted = attention.unaccepted.filter((item) => !overdueIds.has(item.id));

  const nothingToShow =
    attention.overdue.length === 0 && unaccepted.length === 0 && today.length === 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader
        title={`${greeting(me.user.timezone)}, ${firstName}`}
        description={summary(counts, me.user.timezone)}
      />

      {nothingToShow ? (
        <EmptyState
          title={
            (counts.open ?? 0) > 0 ? "Nothing needs you today" : "No work assigned to you yet"
          }
          description={
            (counts.open ?? 0) > 0
              ? "Nothing is overdue, unaccepted, or due today. Your open work is in My Work when you want it."
              : "When someone assigns you work, it appears here — exceptions first, then what is due today, then the week ahead."
          }
          action={
            me.permissions.includes("team.create") ? (
              <Link href="/teams">
                <Button variant="primary">Browse teams</Button>
              </Link>
            ) : undefined
          }
        />
      ) : (
        <>
          <Section
            title="Overdue"
            items={attention.overdue}
            timeZone={me.user.timezone}
            href="/my-work?view=overdue"
          />

          <Section
            title="Assigned, not yet accepted"
            items={unaccepted}
            timeZone={me.user.timezone}
            href="/my-work?view=assigned"
          />

          <Section
            title="Due today"
            items={today}
            timeZone={me.user.timezone}
            href="/my-work?view=today"
          />
        </>
      )}
    </div>
  );
}

function Section({
  title,
  items,
  timeZone,
  href,
}: {
  title: string;
  items: WorkItem[];
  timeZone: string;
  href: string;
}) {
  // An empty section is not rendered at all. A heading over nothing is a hole
  // in the page that the reader has to work out is not an error.
  if (items.length === 0) return null;

  return (
    <section aria-labelledby={`home-${title}`} className="space-y-1">
      <div className="flex items-baseline justify-between">
        <h2
          id={`home-${title}`}
          className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
        >
          {title}
        </h2>
        <Link href={href} className="text-caption text-a-700 hover:underline">
          See all
        </Link>
      </div>

      <div className="border-t border-n-100">
        {items.slice(0, 5).map((item) => (
          <WorkItemRow key={item.id} item={item} timeZone={timeZone} />
        ))}
      </div>
    </section>
  );
}

/**
 * What today looks like, as a sentence.
 *
 * Overdue leads when there is any, because it is the only one of these numbers
 * that describes something already going wrong.
 */
function summary(counts: Record<string, number>, timeZone: string): string {
  const date = new Intl.DateTimeFormat("en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone,
  }).format(new Date());

  const parts: string[] = [];

  if ((counts.overdue ?? 0) > 0) parts.push(`${counts.overdue} overdue`);
  if ((counts.due_today ?? 0) > 0) parts.push(`${counts.due_today} due today`);
  if (parts.length === 0 && (counts.open ?? 0) > 0) parts.push(`${counts.open} open`);

  return parts.length === 0 ? date : `${date} · ${parts.join(" · ")}`;
}

/**
 * Morning, afternoon, or evening — in the reader's timezone, not the server's.
 *
 * A greeting is a small thing to get wrong and a conspicuous one: "Good
 * morning" at 9pm is the product telling someone it does not know where they
 * are (docs/07 §1).
 */
function greeting(timeZone: string): string {
  const hour = Number(
    new Intl.DateTimeFormat("en-GB", { hour: "numeric", hour12: false, timeZone }).format(
      new Date(),
    ),
  );

  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";

  return "Good evening";
}
