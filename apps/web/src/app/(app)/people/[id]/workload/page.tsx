import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { WorkloadBar } from "@/components/ui/WorkloadBar";
import { CommittedWorkTable } from "@/features/people/CommittedWorkTable";
import type { PersonDetail, WorkloadItem, WorkloadItemsMeta } from "@/features/people/types";
import { api, ApiRequestError } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";
import { formatDate } from "@/lib/format";

/**
 * The work behind a person's committed hours.
 *
 * Phase 6's first house rule: **a number must be able to show its work.** The
 * workload bar has shown the hours since Phase 6 and `/people/{id}/workload/items`
 * has been able to explain them for just as long, with nothing calling it — so
 * the one figure a staffing decision gets made from was the one figure nobody
 * could check. That is the house rule's own counter-example, sitting in the
 * product for a phase.
 *
 * Two things make this list an explanation rather than another list of work:
 *
 *   - **`share_hours`, not `estimate_hours`.** An item spanning three weeks
 *     contributes a slice to each. Printing the estimate would give a list
 *     whose numbers do not add up to the bar above it, which is worse than no
 *     drill-through: it invites the reader to trust the wrong arithmetic
 *     (ADR 0009).
 *   - **The residue is stated.** Work the reader may not see, and committed
 *     work with no dates that lands in no week at all, are both counted and
 *     said out loud. A total that does not reconcile with its list reads as a
 *     bug in the product; a total that says why it does not reads as the truth
 *     (ADR 0008).
 *
 * The page is split by what each row does to the figure. It used to be one
 * list, and a person "with 1 item" showed eleven rows: ten of them committed
 * to her but placing no hours in this week, most because their due date had
 * already passed. Those rows are real — they are work she holds — but they are
 * not the answer to "what makes up this number", so they sit in a second panel
 * that says why they are there.
 *
 * The bar is re-rendered from the same meta the API folded the list from, not
 * recomputed here. A second implementation of "committed" is how a figure and
 * its evidence end up arguing with each other.
 */
export default async function WorkloadItemsPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ week?: string }>;
}) {
  const [me, { id }, query] = await Promise.all([requireUser(), params, searchParams]);

  const week = query.week ? `?week=${encodeURIComponent(query.week)}` : "";

  let items: WorkloadItem[];
  let meta: WorkloadItemsMeta;
  let person: PersonDetail;

  try {
    const [breakdown, profile] = await Promise.all([
      api<WorkloadItem[]>(`/people/${id}/workload/items${week}`),
      api<PersonDetail>(`/people/${id}`),
    ]);

    items = breakdown.data;
    meta = breakdown.meta as unknown as WorkloadItemsMeta;
    person = profile.data;
  } catch (error) {
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // Due date first, undated last, then by reference: the order a person reads
  // a week in. The API returns these in no particular order.
  const ordered = [...items].sort(
    (a, b) =>
      (a.due_at ?? "9999").localeCompare(b.due_at ?? "9999") || a.reference.localeCompare(b.reference),
  );

  const counted = ordered.filter((item) => (item.share_hours ?? 0) > 0);
  const held = ordered.filter((item) => (item.share_hours ?? 0) <= 0);
  const listed = counted.reduce((total, item) => total + (item.share_hours ?? 0), 0);

  // A date-only string, so it is formatted in UTC: in a time zone behind UTC,
  // midnight of the 28th is the evening of the 27th.
  const weekLabel = formatDate(meta.week_start, "UTC", locale);

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        {/* Three levels, all of them real: the directory, the person, and the
            week of theirs somebody drilled into (ADR 0026). */}
        <Breadcrumb
          locale={locale}
          items={[
            { label: t("nav.people"), href: "/people" },
            { label: person.name, href: `/people/${id}` },
            { label: t("wl.committed") },
          ]}
        />

        <PageHeader
          title={t("wl.committed")}
          description={t("wl.weekOf", { name: person.name, week: weekLabel })}
          action={
            <div className="flex items-center gap-1">
              <ButtonLink
                href={`/people/${id}/workload?week=${shiftWeek(meta.week_start, -7)}`}
                variant="ghost"
                size="sm"
              >
                {t("wl.prevWeek")}
              </ButtonLink>
              <ButtonLink
                href={`/people/${id}/workload?week=${shiftWeek(meta.week_start, 7)}`}
                variant="ghost"
                size="sm"
              >
                {t("wl.nextWeek")}
              </ButtonLink>
            </div>
          }
        />
      </div>

      <PageBody>
        <Panel id="summary" title={t("wl.summary")}>
          <div className="space-y-3">
            <WorkloadBar
              committedHours={meta.committed_hours}
              capacityHours={meta.capacity_hours}
              itemCount={meta.item_count}
              unestimatedCount={meta.unestimated_count}
              locale={locale}
            />

            <ul className="space-y-1 text-caption">
              <li className="tabular-nums text-n-700">
                {t("wl.listed", { listed: Math.round(listed * 100) / 100, committed: meta.committed_hours })}
              </li>

              {meta.hidden_count > 0 && (
                <li className="text-s-active">{t.plural("wl.hidden", meta.hidden_count)}</li>
              )}

              {meta.undated_count > 0 && (
                <li className="text-s-active">{t.plural("wl.undated", meta.undated_count)}</li>
              )}

              {meta.time_off_hours === null && <li className="text-n-500">{t("workload.noLeave")}</li>}
            </ul>
          </div>
        </Panel>

        {counted.length === 0 ? (
          <EmptyState title={t("wl.empty.title")} description={t("wl.empty.body")} />
        ) : (
          <Panel
            id="counted"
            title={t("wl.counted.title")}
            description={t("wl.counted.description")}
            footer={
              counted.some((item) => item.counted_at_default) ? (
                <p className="text-caption text-n-500">
                  <span className="text-s-active">*</span> {t("wl.defaultEstimate")}{" "}
                  {meta.unestimated_count > 0 && t.plural("wl.unestimated", meta.unestimated_count)}
                </p>
              ) : undefined
            }
            bleed
          >
            <CommittedWorkTable rows={counted} timeZone={me.user.timezone} locale={locale} weekStart={meta.week_start} />
          </Panel>
        )}

        {held.length > 0 && (
          <Panel id="held" title={t("wl.held.title")} description={t("wl.held.description")} bleed>
            <CommittedWorkTable rows={held} timeZone={me.user.timezone} locale={locale} weekStart={meta.week_start} />
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

/** The Monday `days` away from a week's Monday, as the API's `?week=` reads it. */
function shiftWeek(weekStart: string, days: number): string {
  const date = new Date(`${weekStart}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);

  return date.toISOString().slice(0, 10);
}
