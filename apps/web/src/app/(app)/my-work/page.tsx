import Link from "next/link";
import { ButtonLink } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { clsx } from "@/lib/clsx";

/**
 * My Work (docs/08 §3).
 *
 * The ORDERING is the design: exceptions first, then commitments, then
 * foresight, then blockers. A person opening this should know what to do next
 * in about three seconds without reading anything twice.
 *
 * Nothing here is a card with a number on it.
 */

// The labels and empty states live in the dictionaries under the same keys
// (`myWork.view.<key>`, `myWork.empty.<key>.*`, ADR 0060).
//
// Empty states are written per view, not shared. "No results" tells someone
// nothing. An empty Overdue tab is GOOD NEWS and should read that way; an
// empty Today tab means something different again (docs/07 §7).
const VIEWS = ["today", "upcoming", "overdue", "assigned", "waiting_on_others", "completed"] as const;

type View = (typeof VIEWS)[number];

export default async function MyWorkPage({
  searchParams,
}: {
  searchParams: Promise<{ view?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  const view: View = VIEWS.find((v) => v === params.view) ?? "today";
  // Translated (ADR 0060): every word here, and the rows are handed the locale.
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // Fetched in parallel: three sequential awaits would triple the time to
  // first paint for no benefit (docs/07 §2).
  const [{ data: items }, { data: counts }] = await Promise.all([
    api<WorkItem[]>(`/me/work?view=${view}&limit=100`),
    api<Record<string, number>>("/me/work/counts"),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("nav.myWork")}
        description={
          counts.overdue > 0
            ? `${t("count.overdue", { count: counts.overdue })} · ${t("count.open", { count: counts.open })}`
            : t("count.open", { count: counts.open })
        }
        // No project in the link, deliberately: work with no project is a
        // first-class case (ADR 0004), and this is the screen where somebody
        // notes down something they have to do rather than files it.
        action={
          me.permissions.includes("work_item.create") ? (
            <ButtonLink variant="primary" href="/work/new">
              {t("myWork.new")}
            </ButtonLink>
          ) : undefined
        }
      />

      <p className="text-caption text-n-500">
        <Link
          href="/reports/personal"
          className="text-a-500 underline underline-offset-2"
        >
          {t("myWork.report.link")}
        </Link>{" "}
        {t("myWork.report.rest")}
      </p>


      <nav aria-label={t("myWork.views")} className="flex gap-1 overflow-x-auto border-b border-n-100">
        {VIEWS.map((v) => {
          const active = v === view;
          const badge =
            v === "overdue"
              ? counts.overdue
              : v === "waiting_on_others"
                ? counts.waiting_on_others
                : 0;

          return (
            <Link
              key={v}
              href={`/my-work?view=${v}`}
              aria-current={active ? "page" : undefined}
              className={clsx(
                "flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-body transition-colors duration-[120ms]",
                active
                  ? "border-a-500 font-medium text-n-900"
                  : "border-transparent text-n-500 hover:text-n-700",
              )}
            >
              {t(`myWork.view.${v}`)}
              {badge > 0 && (
                <span
                  className={clsx(
                    "rounded-full px-1.5 text-micro font-semibold",
                    v === "overdue" ? "bg-s-danger/10 text-s-danger" : "bg-n-100 text-n-500",
                  )}
                >
                  {badge}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

      <PageBody>
        {items.length === 0 ? (
          <EmptyState title={t(`myWork.empty.${view}.title`)} description={t(`myWork.empty.${view}.body`)} />
        ) : (
          <Panel
            id="my-work"
            title={t(`myWork.view.${view}`)}
            description={t.plural("myWork.items", items.length)}
            bleed
          >
            <div className="divide-y divide-n-100">
              {items.map((item) => (
                <WorkItemRow key={item.id} item={item} timeZone={me.user.timezone} locale={locale} />
              ))}
            </div>
          </Panel>
        )}
      </PageBody>
    </div>
  );
}
