import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { FeedSubscription } from "@/features/calendar/FeedSubscription";
import { Agenda } from "@/features/calendar/Agenda";
import { MonthGrid } from "@/features/calendar/MonthGrid";
import { SourceFilter } from "@/features/calendar/SourceFilter";
import type { CalendarEvent, CalendarSource, FeedStatus } from "@/features/calendar/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

const ALL_SOURCES: CalendarSource[] = ["work", "milestones", "recurring"];

/**
 * The calendar (docs/08 §5, docs/10 Phase 5).
 *
 * A view, never a record: every event on it is a deadline that already exists
 * somewhere else, shown through the same visibility rules that govern it there.
 * Nothing is created here, which is why there is no "new event" button — an
 * event with no work behind it would be the one thing on this page that no
 * other part of the product knows about.
 */
export default async function CalendarPage({
  searchParams,
}: {
  searchParams: Promise<{ month?: string; sources?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const month = /^\d{4}-\d{2}$/.test(params.month ?? "")
    ? `${params.month}-01`
    : `${new Date().toISOString().slice(0, 7)}-01`;

  const sources = (params.sources ?? "")
    .split(",")
    .filter((source): source is CalendarSource =>
      ALL_SOURCES.includes(source as CalendarSource),
    );

  const active = sources.length > 0 ? sources : ALL_SOURCES;

  // A month plus the days either side that the grid shows: fetching exactly the
  // month would leave the leading and trailing cells empty, which reads as "you
  // have nothing on" for days that are simply outside the request.
  const from = new Date(`${month}T00:00:00Z`);
  from.setUTCDate(from.getUTCDate() - 7);

  const to = new Date(`${month}T00:00:00Z`);
  to.setUTCMonth(to.getUTCMonth() + 1);
  to.setUTCDate(to.getUTCDate() + 7);

  const query = new URLSearchParams({
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
    sources: active.join(","),
  });

  const [{ data: events }, feed] = await Promise.all([
    api<CalendarEvent[]>(`/calendar?${query}`),
    api<FeedStatus>("/calendar/feed")
      .then((r) => r.data)
      // A missing subscription is a normal state, not an error; the control
      // below renders the "create one" path from exactly the same absence.
      .catch(() => null),
  ]);

  const label = new Intl.DateTimeFormat("en-GB", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${month}T00:00:00Z`));

  return (
    <div className="space-y-4">
      <PageHeader title="Calendar" description={label} action={<FeedSubscription feed={feed} />} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <nav className="flex items-center gap-4 text-body-sm">
          <Link href={`/calendar?${monthQuery(month, -1, params.sources)}`} className="text-a-700 hover:underline">
            ← Previous
          </Link>
          <Link href={`/calendar?${monthQuery(month, 1, params.sources)}`} className="text-a-700 hover:underline">
            Next →
          </Link>
        </nav>

        <SourceFilter active={active} />
      </div>

      {/* Two components, one dataset: a grid to compare a month across columns,
          a list to answer "what is coming" with a thumb (docs/08 §6). */}
      <div className="md:hidden">
        <Agenda events={events} timeZone={me.user.timezone} />
      </div>

      <div className="hidden md:block">
        <MonthGrid month={month} events={events} timeZone={me.user.timezone} />
      </div>
    </div>
  );
}

function monthQuery(month: string, shift: number, sources?: string): string {
  const date = new Date(`${month}T00:00:00Z`);
  date.setUTCMonth(date.getUTCMonth() + shift);

  const params = new URLSearchParams({ month: date.toISOString().slice(0, 7) });
  if (sources) params.set("sources", sources);

  return params.toString();
}
