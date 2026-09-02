import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { CompletionsTable } from "@/features/insights/CompletionsTable";
import type { FlowCompletion, FlowCompletionsMeta } from "@/features/insights/types";
import { formatDate } from "@/lib/format";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The completions behind a flow figure (docs/10, Phase 6 exit criteria).
 *
 * "Every number traces to a documented definition, and each is clickable
 * through to the underlying records. A number a user cannot drill into does not
 * ship." The API side of this has existed since ADR 0007; until this page there
 * was no way to reach it from the figure it explains, which is the same defect
 * as not having it.
 *
 * The window arrives in the query string rather than being recomputed here, so
 * the row that was clicked and the list that opens are asking the API the same
 * question. Recomputing "the week of the 14th" on this side is how a
 * drill-through comes to disagree with the number above it.
 */
export default async function FlowCompletionsPage({
  searchParams,
}: {
  searchParams: Promise<{ from?: string; to?: string; project_id?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const query = new URLSearchParams();
  if (params.from) query.set("from", params.from);
  if (params.to) query.set("to", params.to);
  if (params.project_id) query.set("project_id", params.project_id);

  const { data: completions, meta } = await api<FlowCompletion[]>(
    `/insights/flow/items?${query}`,
  );
  const window = meta as unknown as FlowCompletionsMeta;

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div className="space-y-3">
        <Link href="/reports" className="text-body-sm text-n-500 hover:text-a-700">
          ← Flow
        </Link>

        <PageHeader
          title="Completions"
          description={`${window.throughput} completed between ${formatDate(
            window.from,
            me.user.timezone,
          )} and ${formatDate(window.to, me.user.timezone)}`}
        />
      </div>

      {completions.length === 0 ? (
        <EmptyState
          title={
            window.hidden_count > 0
              ? "Nothing here you can see"
              : "Nothing completed in this window"
          }
          description={
            window.hidden_count > 0
              ? `All ${window.hidden_count} of the completions in this window are in work you do not have access to. The figure on the Flow page counts them, because how much the organization delivered is not a fact about who is reading.`
              : "Throughput counts items entering Done. Once work is completed in this window, it appears here."
          }
        />
      ) : (
        <>
          <CompletionsTable completions={completions} timeZone={me.user.timezone} />

          {window.hidden_count > 0 && (
            // The list and the headline are answering two different questions,
            // and this line is what stops that reading as an arithmetic error.
            // Same split as the workload drill-through: the aggregate is a fact
            // about the organization, what may be READ is a fact about the
            // reader.
            <p className="max-w-[72ch] text-caption text-s-active">
              {window.hidden_count} further{" "}
              {window.hidden_count === 1 ? "completion is" : "completions are"} counted in the
              figures on the Flow page but not listed here — {window.hidden_count === 1 ? "it is" : "they are"}{" "}
              in work you do not have access to.
            </p>
          )}

          <p className="max-w-[72ch] text-caption text-n-500">
            Cycle time is measured from the first time an item entered In Progress to the last
            time it reached Done, over the whole of its history rather than only inside this
            window — an item finished on Monday may have started last quarter. Items that never
            entered In Progress are counted in the throughput and excluded from every
            percentile.
          </p>
        </>
      )}
    </div>
  );
}
