import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { EmptyState } from "@/components/ui/EmptyState";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * The work sitting in one state category right now (ADR 0010, docs/10).
 *
 * This exists because the bottleneck table has a "sitting there now" column,
 * and a number a user cannot drill into does not ship. It is deliberately a
 * snapshot with no window: the figure it explains is one too.
 *
 * The list comes from the work item endpoint with its own visibility rule
 * applied, so two readers can legitimately see different lists behind the same
 * count — the count is a fact about the organization and the list is a fact
 * about the reader, the same split as every other drill-through here.
 */
const CATEGORIES = new Set(["backlog", "todo", "in_progress", "in_review", "blocked"]);

export default async function WaitingPage({
  searchParams,
}: {
  searchParams: Promise<{ category?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const category = params.category ?? "";

  // Only categories work can WAIT in. `done` and `cancelled` are where work
  // stops, and a queue of finished work is not a queue.
  if (!CATEGORIES.has(category)) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const label = t(`stateCat.${category}` as MessageKey);

  const { data: items } = await api<WorkItem[]>(
    `/work-items?filter[state_category]=${category}&sort=due_at&limit=100`,
  ).catch(() => ({ data: [] as WorkItem[] }));

  return (
    // The product's own width, not this page's: it used to centre itself at a
    // max-width nothing else uses, so arriving here from Flow moved the left
    // edge of everything (ADR 0024).
    <div className="space-y-5">
      <div className="space-y-3">
        {/* The same trail its sibling drill-through already had, instead of a
            hand-drawn back link (ADR 0026). */}
        <Breadcrumb
          items={[
            { label: t("nav.flow"), href: "/reports" },
            { label: t("wait.title", { category: label }) },
          ]}
          locale={locale}
        />

        <PageHeader
          title={t("wait.title", { category: label })}
          description={t.plural("wait.summary", items.length)}
        />
      </div>

      <PageBody>
        {items.length === 0 ? (
          <EmptyState
            title={t("wait.empty.title")}
            description={t("wait.empty.body", { category: label })}
          />
        ) : (
          <Panel
            id="waiting"
            title={t("wait.panel", { category: label })}
            description={t("wait.panel.description")}
            footer={
              <p className="max-w-prose text-caption text-n-500">
                {t("wait.footer")}
              </p>
            }
            bleed
          >
            {items.map((item) => (
              <WorkItemRow key={item.id} item={item} timeZone={me.user.timezone} locale={locale} />
            ))}
          </Panel>
        )}
      </PageBody>
    </div>
  );
}
