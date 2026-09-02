import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { FlowTable } from "@/features/insights/FlowTable";
import type { Flow } from "@/features/insights/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Organization Home — "how is work flowing?" (docs/08 §3, ADR 0007).
 *
 * Trends, not a scoreboard. There is no per-person breakdown here and no filter
 * that would produce one: `docs/02` §11 rules out reducing individual
 * performance to a ranked number, and per-assignee cycle time is exactly that
 * number under a neutral name.
 *
 * The definition is printed on the page rather than left in a document nobody
 * opens. Phase 6's exit criterion is that every number traces to a documented
 * definition — a definition the reader cannot see is one they will invent.
 */
export default async function ReportsPage({
  searchParams,
}: {
  searchParams: Promise<{ from?: string; to?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const query = new URLSearchParams();
  if (params.from) query.set("from", params.from);
  if (params.to) query.set("to", params.to);

  const { data: flow } = await api<Flow>(`/insights/flow?${query}`);

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader
        title="Flow"
        description={`${flow.throughput} completed between ${flow.from} and ${flow.to}`}
      />

      {flow.throughput === 0 ? (
        <EmptyState
          title="Nothing completed in this window"
          description="Cycle time is measured from work items moving through the workflow. Once work starts being completed, its throughput and cycle time appear here."
        />
      ) : (
        <>
          <section aria-labelledby="headline" className="space-y-2">
            <h2 id="headline" className="sr-only">
              Headline figures
            </h2>

            <dl className="flex flex-wrap gap-x-10 gap-y-3">
              <Figure
                term="Median cycle time"
                value={flow.cycle_time_p50_hours}
                note="half of completed work took less"
              />
              <Figure
                term="85th percentile"
                value={flow.cycle_time_p85_hours}
                note="the number to make a promise from"
              />
              <div>
                <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                  Measured
                </dt>
                <dd className="mt-0.5 text-h2 tabular-nums text-n-900">
                  {flow.measured}
                  <span className="ml-1 text-body text-n-500">of {flow.throughput}</span>
                </dd>
                {flow.unmeasurable > 0 && (
                  // Named rather than dropped: an item that went straight to
                  // done has no duration to measure, and inventing a zero for
                  // it would pull every percentile down.
                  <dd className="mt-0.5 text-caption text-s-active">
                    {flow.unmeasurable} never entered In Progress
                  </dd>
                )}
              </div>
            </dl>
          </section>

          <FlowTable flow={flow} timeZone={me.user.timezone} />

          <p className="max-w-[72ch] text-caption text-n-500">
            Cycle time is measured from the first time an item entered In Progress to the
            last time it reached Done. Time spent blocked is included — waiting is part of
            how long work took. Cancelled work is excluded, and an item that was reopened
            is counted once across the whole span. Percentiles are nearest-rank, so every
            figure above is a duration something actually took.
          </p>
        </>
      )}
    </div>
  );
}

function Figure({
  term,
  value,
  note,
}: {
  term: string;
  value: number | null;
  note: string;
}) {
  return (
    <div>
      <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">{term}</dt>
      <dd className="mt-0.5 text-h2 tabular-nums text-n-900">
        {value === null ? "—" : value < 48 ? `${Math.round(value)} h` : `${(value / 24).toFixed(1)} d`}
      </dd>
      <dd className="text-caption text-n-500">{note}</dd>
    </div>
  );
}
