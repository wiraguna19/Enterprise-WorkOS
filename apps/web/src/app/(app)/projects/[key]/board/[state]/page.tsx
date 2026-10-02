import Link from "next/link";
import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusChip } from "@/components/ui/StatusChip";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import { DueDate } from "@/features/work-item/components/DueDate";
import type { BoardColumn, Project, WorkItem } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * One board column, in full (ADR 0012 §6, and the debt that ADR left).
 *
 * The board shows the first fifty cards of each column and says how many it
 * left out. That is honest and, on its own, useless: a cap with no way past it
 * is a disappearance with a footnote. This is the way past it.
 *
 * Filtered by STATE rather than by category. A column is one state, and five
 * states can share a category — `filter.state_category` would return a superset
 * that looks like this column with far too much in it, which is the kind of
 * wrong that reads as a data problem rather than a query one.
 */
const PER_PAGE = 100;

export default async function BoardColumnPage({
  params,
}: {
  params: Promise<{ key: string; state: string }>;
}) {
  const [me, { key, state }] = await Promise.all([requireUser(), params]);

  let board: { project: Project; columns: BoardColumn[] };

  try {
    ({ data: board } = await api<{ project: Project; columns: BoardColumn[] }>(
      `/projects/${key}/board`,
    ));
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // The board is asked for the column's identity and its TRUE total, not for
  // its cards — the cards come from the list below, which is not capped at
  // fifty. Deriving the total here from what the board sent would reproduce the
  // cap on the page built to escape it.
  const column = board.columns.find((candidate) => candidate.state.key === state);

  if (!column) notFound();

  const { data: items, meta } = await api<WorkItem[]>(
    `/work-items?filter[project_id]=${board.project.id}`
      + `&filter[state_id]=${column.state.id}&limit=${PER_PAGE}`,
  );

  const pagination = meta?.pagination as { has_more?: boolean } | undefined;
  // Translated (ADR 0060). The column's name is the workflow's own.
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <Breadcrumb
          locale={locale}
          items={[
            { label: t("nav.projects"), href: "/projects" },
            { label: board.project.name, href: `/projects/${key}/board` },
            { label: column.state.label },
          ]}
        />

        <PageHeader
          title={column.state.label}
          description={t("items.summary", { count: column.total, project: board.project.key })}
        />
      </div>

      {items.length === 0 ? (
        <EmptyState
          title={t("items.empty")}
          description={t("column.empty", { state: column.state.label })}
        />
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
                  <PriorityIcon priority={item.priority} locale={locale} />
                  <span className="shrink-0">
                    <DueDate
                      value={item.due_at}
                      overdue={item.is_overdue}
                      timeZone={me.user.timezone}
                      locale={locale}
                    />
                  </span>
                </Link>
              </li>
            ))}
          </ul>

          {pagination?.has_more && (
            // Said rather than left to be worked out from a length. This page
            // exists because a silent cap is the defect; reproducing one here
            // would be the same mistake with a longer list.
            <p className="max-w-[72ch] text-caption text-s-active">
              {t("column.firstOf", { shown: PER_PAGE, total: column.total })}
            </p>
          )}

          <p className="max-w-[72ch] text-caption text-n-500">
            {t("column.order.before")}{" "}
            <Link href={`/projects/${key}/board`} className="text-a-500 underline underline-offset-2">
              {t("column.order.board")}
            </Link>{" "}
            {t("column.order.after")}
          </p>
        </>
      )}

      <div className="pt-2">
        <StatusChip category={column.state.category} label={column.state.label} />
      </div>
    </div>
  );
}
