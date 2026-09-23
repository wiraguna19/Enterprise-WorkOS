import { notFound } from "next/navigation";
import { EmptyState } from "@/components/ui/EmptyState";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { describeTrigger } from "@/features/workflow/describe";
import type { Rule, RuleRun } from "@/features/workflow/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";

/**
 * Why a rule did or did not fire.
 *
 * The first question anyone asks of any automation system, unanswerable
 * without the run log — and the log has been written since Phase 4 with
 * nothing able to read it. `RuleEngine` records the SKIPS as well as the
 * matches for exactly this screen: a list of successes cannot answer "why
 * didn't my rule run", which is the question actually being asked.
 *
 * Reached from the rule, never from an index of runs: a run means nothing
 * without the predicate it was measured against.
 *
 * Gated on `workflow.manage` by the route, one rung above the rules list. What
 * the rules ARE is configuration; what they DID names subjects the reader may
 * not be entitled to see.
 */
export default async function RuleRunsPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  const [rules, runs] = await Promise.all([
    api<Rule[]>("/workflow-rules", { tags: ["workflow-rules"] }).then((r) => r.data),
    api<RuleRun[]>(`/workflow-rules/${id}/runs`).then(
      (r) => r.data,
      (error: unknown) => {
        // A rule you may not read is one whose existence you should not be
        // able to confirm — the same fold the approval page makes.
        if (error instanceof ApiRequestError && (error.status === 403 || error.status === 404)) {
          notFound();
        }

        throw error;
      },
    ),
  ]);

  const rule = rules.find((candidate) => candidate.id === id);

  if (!rule) notFound();

  const matched = runs.filter((run) => run.matched).length;
  const failed = runs.filter((run) => run.outcome === "failed").length;

  return (
    <div className="space-y-5">
      {/* Two levels, not three. A rule really is inside the rules list, so
          that link is a promise this page can keep; "Settings" in front of it
          is the invented level ADR 0026 warns about — nobody navigates to a
          rule by way of the settings index. */}
      <Breadcrumb
        items={[
          { label: "Automation rules", href: "/settings/rules" },
          { label: rule.name },
        ]}
      />

      <PageHeader
        title={rule.name}
        description={
          runs.length === 0
            ? describeTrigger(rule.trigger)
            : `${runs.length} most recent · ${matched} matched · ${failed} failed`
        }
      />

      <PageBody>
        {runs.length === 0 ? (
          <EmptyState
            title="It has not run yet"
            description={`Nothing has happened that this rule watches for — ${describeTrigger(rule.trigger).toLowerCase()}. A rule with no runs is not evidence that it works.`}
          />
        ) : (
        <Panel
          id="runs"
          title="Recent runs"
          description={
            'Newest first. Skipped runs are listed too — "did not match" is the commonest ' +
            'answer to "why didn\'t it fire", and a log of only the matches cannot give it.'
          }
          footer={
            <p className="text-caption text-n-500">
              A run is written when the engine evaluates this rule. Nothing is written for a
              preview, and a run the engine could not record is reported in the application log
              rather than counted against the rule (ADR 0036).
            </p>
          }
          bleed
        >
          <ul className="divide-y divide-n-100">
            {runs.map((run) => (
              <li
                key={run.id}
                className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3"
              >
                <span className="w-40 shrink-0 text-caption tabular-nums text-n-500">
                  {formatDateTime(run.occurred_at, me.user.timezone)}
                </span>

                <span className="min-w-0 flex-1">
                  <Outcome run={run} />
                  {run.error && (
                    // The error verbatim. A run log that summarises the failure
                    // is a run log that cannot be used to fix it.
                    <span className="mt-0.5 block break-words font-mono text-micro text-s-danger">
                      {run.error}
                    </span>
                  )}
                  <span className="mt-0.5 block truncate font-mono text-micro text-n-500">
                    {run.subject_type} {run.subject_id}
                  </span>
                </span>

                <span className="w-28 shrink-0 text-right text-caption tabular-nums text-n-500">
                  {run.duration_ms === null ? "—" : `${run.duration_ms} ms`}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
        )}
      </PageBody>
    </div>
  );
}

/**
 * What happened, in the terms the engine records.
 *
 * "Skipped" is the outcome this screen exists for: the rule was considered and
 * its conditions did not hold. Rendering only the matches would leave the
 * commonest answer to "why didn't it fire" looking like the rule was never
 * reached at all.
 */
function Outcome({ run }: { run: RuleRun }) {
  if (run.outcome === "failed") {
    return <span className="text-body-sm text-s-danger">failed</span>;
  }

  if (!run.matched) {
    return <span className="text-body-sm text-n-700">skipped — its conditions did not hold</span>;
  }

  const count = run.actions_run ?? 0;

  return (
    <span className="text-body-sm text-n-700">
      matched — {count} {count === 1 ? "action" : "actions"} run
    </span>
  );
}
