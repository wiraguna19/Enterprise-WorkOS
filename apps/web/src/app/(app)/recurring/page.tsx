import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { StopButton } from "@/features/recurrence/StopButton";
import { describe } from "@/features/recurrence/schedule";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
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

  // The nav hides this entry without the permission; the PAGE has to refuse it
  // too. A URL is typed, pasted and bookmarked, and until now the two screens
  // whose reads have no fallback answered a 403 with "Something went wrong"
  // while the two that do fall back answered with an empty state — which is
  // worse, because "no departments yet" is a confident lie about somebody
  // else's organization. 404 rather than 403, like every other refusal in this
  // product: whether the thing exists is not this page's to disclose.
  if (!me.permissions.includes("work_item.create")) notFound();


  const recurrences = await api<Recurrence[]>("/recurrences")
    .then((r) => r.data)
    .catch(() => [] as Recurrence[]);

  const mayCreate = me.permissions.includes("work_item.create");

  // Ordered by the API: active first, then by when they next run. Nothing is
  // re-sorted here — a second ordering is a second opinion about which rule
  // matters most, and the server already has one.
  const active = recurrences.filter((recurrence) => recurrence.is_active);
  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("rec.title")}
        description={t("rec.running", { count: active.length })}
        action={
          mayCreate ? (
            <ButtonLink href="/recurring/new" variant="primary">
              {t("rec.new")}
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
      {recurrences.length === 0 ? (
        <EmptyState
          title={t("rec.empty.title")}
          description={t("rec.empty.body")}
          action={
            mayCreate ? (
              <ButtonLink href="/recurring/new" variant="primary">
                {t("rec.first")}
              </ButtonLink>
            ) : undefined
          }
        />
      ) : (
        <Panel
          id="recurrences"
          title={t("rec.panel")}
          description={t("rec.panelDesc")}
          bleed
        >
        <ul className="divide-y divide-n-100">
          {recurrences.map((recurrence) => (
            <li
              key={recurrence.id}
              className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3"
            >
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-n-900">
                  {recurrence.template.title ?? t("review.untitled")}
                </span>
                <span className="block truncate text-caption text-n-500">
                  {describe(recurrence.rrule, locale)}
                  {recurrence.ends_at &&
                    ` · ${t("rec.until", { date: formatDateTime(recurrence.ends_at, me.user.timezone, locale) })}`}
                </span>
              </span>

              {/* What it has produced, not merely that it exists. */}
              <span className="shrink-0 text-caption tabular-nums text-n-500">
                {t.plural("rec.soFar", recurrence.created_count)}
              </span>

              <span className="w-44 shrink-0 text-caption tabular-nums text-n-500">
                {recurrence.is_active
                  ? t("rec.next", { date: formatDateTime(recurrence.next_run_at, me.user.timezone, locale) })
                  : t("rec.stopped")}
              </span>

              {recurrence.is_active && mayCreate && (
                <StopButton
                  id={recurrence.id}
                  schedule={recurrence.template.title ?? describe(recurrence.rrule, locale)}
                />
              )}
            </li>
          ))}
        </ul>
        </Panel>
      )}
      </PageBody>
    </div>
  );
}
