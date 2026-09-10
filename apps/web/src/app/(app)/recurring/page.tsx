import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { StopButton } from "@/features/recurrence/StopButton";
import { describe } from "@/features/recurrence/schedule";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";

/**
 * Standing instructions to create work (docs/03 §4).
 *
 * Phase 5 shipped RRULE recurrence whole — the rule, the materializer, the
 * scheduled command, the `recurrence_id` on every item it produces — and
 * nothing in the product could create one, so recurring work existed only in
 * the seed and through curl. It was the last entry on the reachability bill
 * that belonged to this product rather than to Phase 7.
 *
 * Each row answers the three questions a standing rule has to: when it runs,
 * when it runs NEXT, and what it has actually produced. The third is the one
 * that turns a rule into something auditable — a schedule nobody can check is
 * a schedule nobody trusts (docs/02 §7).
 */
type Recurrence = {
  id: string;
  rrule: string;
  template: { title?: string };
  is_active: boolean;
  next_run_at: string | null;
  last_run_at: string | null;
  ends_at: string | null;
  created_count: number;
};

export default async function RecurringPage() {
  const me = await requireUser();

  const recurrences = await api<Recurrence[]>("/recurrences")
    .then((r) => r.data)
    .catch(() => [] as Recurrence[]);

  const mayCreate = me.permissions.includes("work_item.create");

  // Ordered by the API: active first, then by when they next run. Nothing is
  // re-sorted here — a second ordering is a second opinion about which rule
  // matters most, and the server already has one.
  const active = recurrences.filter((recurrence) => recurrence.is_active);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Recurring work"
        description={`${active.length} running`}
        action={
          mayCreate ? (
            <ButtonLink href="/recurring/new" variant="primary">
              New recurring work
            </ButtonLink>
          ) : undefined
        }
      />

      {recurrences.length === 0 ? (
        <EmptyState
          title="Nothing recurs yet"
          description="A recurring rule creates the same work on a schedule — a weekly checklist, a monthly report — and every item it makes points back here."
          action={
            mayCreate ? (
              <ButtonLink href="/recurring/new" variant="primary">
                Set up the first one
              </ButtonLink>
            ) : undefined
          }
        />
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {recurrences.map((recurrence) => (
            <li key={recurrence.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-3">
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-n-900">
                  {recurrence.template.title ?? "Untitled"}
                </span>
                <span className="block truncate text-caption text-n-500">
                  {describe(recurrence.rrule)}
                  {recurrence.ends_at &&
                    ` · until ${formatDateTime(recurrence.ends_at, me.user.timezone)}`}
                </span>
              </span>

              {/* What it has produced, not merely that it exists. */}
              <span className="shrink-0 text-caption tabular-nums text-n-500">
                {recurrence.created_count}{" "}
                {recurrence.created_count === 1 ? "item" : "items"} so far
              </span>

              <span className="w-44 shrink-0 text-caption tabular-nums text-n-500">
                {recurrence.is_active
                  ? `next ${formatDateTime(recurrence.next_run_at, me.user.timezone)}`
                  : "stopped"}
              </span>

              {recurrence.is_active && mayCreate && (
                <StopButton
                  id={recurrence.id}
                  schedule={recurrence.template.title ?? describe(recurrence.rrule)}
                />
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
