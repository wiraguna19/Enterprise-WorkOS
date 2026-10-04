import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { EmptyState } from "@/components/ui/EmptyState";
import { CompletionsTable } from "@/features/insights/CompletionsTable";
import type { FlowCompletion, FlowCompletionsMeta } from "@/features/insights/types";
import { formatDate } from "@/lib/format";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

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
  searchParams: Promise<{
    from?: string;
    to?: string;
    project_id?: string;
    department_id?: string;
    late?: string;
  }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const query = new URLSearchParams();
  if (params.from) query.set("from", params.from);
  if (params.to) query.set("to", params.to);
  if (params.project_id) query.set("project_id", params.project_id);
  // Both filters were linked to from the Flow page — the late rate and each
  // department row — and dropped here, so either link opened the whole
  // window's list under a figure that counted only part of it. The API has
  // applied both since ADR 0010; this page now asks it to.
  if (params.department_id) query.set("department_id", params.department_id);
  if (params.late === "1") query.set("late", "1");

  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const { data: completions, meta } = await api<FlowCompletion[]>(
    `/insights/flow/items?${query}`,
  );
  const window = meta as unknown as FlowCompletionsMeta;

  return (
    // `PageBody` rather than a page-local `max-w-4xl`: this screen was
    // centring itself at a width no other page uses, so moving between Flow
    // and its drill-through moved the left edge of the product (ADR 0024).
    <div className="space-y-5">
      <div className="space-y-3">
        <Breadcrumb
          items={[
            { label: t("nav.flow"), href: "/reports" },
            { label: window.late_only ? t("comp.lateTitle") : t("comp.title") },
          ]}
          locale={locale}
        />

        <PageHeader
          title={window.late_only ? t("comp.lateTitle") : t("comp.title")}
          description={t(window.late_only ? "comp.lateSummary" : "flow.summary", {
            count: window.throughput,
            from: formatDate(window.from, me.user.timezone, locale),
            to: formatDate(window.to, me.user.timezone, locale),
          })}
        />
      </div>

      <PageBody>
      {completions.length === 0 ? (
        <EmptyState
          title={
            window.hidden_count > 0
              ? t("comp.hidden.title")
              : t("flow.empty.title")
          }
          description={
            window.hidden_count > 0
              ? t("comp.hidden.body", { count: window.hidden_count })
              : t("comp.empty.body")
          }
        />
      ) : (
        <Panel
          id="completions"
          title={t("comp.panel")}
          description={t("comp.panel.description")}
          footer={
            <p className="max-w-prose text-caption text-n-500">
              {t("comp.footer")}
            </p>
          }
          bleed
        >
          <CompletionsTable completions={completions} timeZone={me.user.timezone} locale={locale} />

          {window.hidden_count > 0 && (
            // The list and the headline are answering two different questions,
            // and this line is what stops that reading as an arithmetic error.
            // Same split as the workload drill-through: the aggregate is a fact
            // about the organization, what may be READ is a fact about the
            // reader.
            <p className="border-t border-n-100 px-4 py-3 text-caption text-s-active">
              {t.plural("comp.hiddenTail", window.hidden_count)}
            </p>
          )}
        </Panel>
      )}
      </PageBody>
    </div>
  );
}
