import Link from "next/link";
import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import type { HealthItem, HealthItemsMeta } from "@/features/insights/types";
import { formatDateTime } from "@/lib/format";
import { api, ApiRequestError } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * The records behind one health signal (docs/10, Phase 6 exit criteria).
 *
 * The signal is not re-derived here — it is passed to the API, which applies
 * the same predicate the count came from. Reimplementing "overdue" on this side
 * would give the page a second definition, and the first time the two disagreed
 * the number and its evidence would be arguing with each other.
 */
// Dictionary keys (ADR 0060).
const TITLES: Record<string, { title: MessageKey; description: MessageKey }> = {
  overdue: { title: "health.overdue.name", description: "items.overdue.description" },
  blocked: { title: "health.blocked.name", description: "items.blocked.description" },
  open: { title: "items.open.title", description: "items.open.description" },
  stale: { title: "items.stale.title", description: "items.stale.description" },
};

export default async function HealthItemsPage({
  params,
  searchParams,
}: {
  params: Promise<{ key: string }>;
  searchParams: Promise<{ signal?: string }>;
}) {
  const [me, { key }, query] = await Promise.all([requireUser(), params, searchParams]);

  const signal = query.signal ?? "open";
  const heading = TITLES[signal];

  if (!heading) notFound();

  let items: HealthItem[];
  let meta: HealthItemsMeta;

  try {
    const response = await api<HealthItem[]>(
      `/insights/projects/${key}/health/items?signal=${signal}`,
    );

    items = response.data;
    meta = response.meta as unknown as HealthItemsMeta;
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <Breadcrumb
          locale={locale}
          items={[
            { label: t("nav.projects"), href: "/projects" },
            { label: key, href: `/projects/${key}/overview` },
            { label: t("items.crumb") },
          ]}
        />

        <PageHeader
          title={t(heading.title)}
          description={t("items.summary", { count: meta.total, project: meta.project })}
        />
      </div>

      {items.length === 0 ? (
        <EmptyState title={t("items.empty")} description={t(heading.description)} />
      ) : (
        <>
          <ul className="border-y border-n-100">
            {items.map((item) => (
              <li key={item.id}>
                <Link
                  href={`/work/${item.reference}`}
                  className="flex items-baseline gap-3 border-b border-n-100 px-2 py-2 last:border-b-0 hover:bg-n-25"
                >
                  <span className="w-16 shrink-0 font-mono text-caption text-n-500">
                    {item.reference}
                  </span>
                  <span className="min-w-0 flex-1 truncate font-medium text-n-900">
                    {item.title}
                  </span>
                  <span className="shrink-0 text-caption tabular-nums text-n-500">
                    {item.due_at ? formatDateTime(item.due_at, me.user.timezone, locale) : t("items.noDue")}
                  </span>
                </Link>
              </li>
            ))}
          </ul>

          {meta.hidden_count > 0 && (
            // The count on the overview is a fact about the project; this list
            // is a fact about the reader. Saying so is what stops the
            // difference reading as an arithmetic error (ADR 0008).
            <p className="max-w-[72ch] text-caption text-s-active">
              {t.plural("items.hidden", meta.hidden_count)}
            </p>
          )}

          <p className="max-w-[72ch] text-caption text-n-500">{t(heading.description)}</p>
        </>
      )}
    </div>
  );
}
