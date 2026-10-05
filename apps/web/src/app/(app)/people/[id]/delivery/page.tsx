import Link from "next/link";
import { notFound } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import type { DeliveredItem, Delivery } from "@/features/people/delivery";
import type { PersonDetail } from "@/features/people/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDate } from "@/lib/format";

/**
 * The items behind a person's delivery figures (ADR 0062, "Delivery without a
 * KPI"): each item once, in the week its last completion fell, with whether it
 * was on time. The count of rows IS the figure on their page.
 */
export default async function DeliveryPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ from?: string; to?: string }>;
}) {
  const [me, { id }, query] = await Promise.all([requireUser(), params, searchParams]);
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const refuse = (error: unknown): never => {
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 422)) notFound();
    throw error;
  };

  // The window the profile's figures were folded over, unless the link asked
  // for another one — so the default list is exactly the figure that led here.
  const isDate = (value: string | undefined): value is string => /^\d{4}-\d{2}-\d{2}$/.test(value ?? "");
  const span =
    isDate(query.from) && isDate(query.to)
      ? { from: query.from, to: query.to }
      : await api<Delivery>(`/people/${id}/delivery`).then((r) => r.data.summary).catch(refuse);
  const { from, to } = span;

  const [person, items] = await Promise.all([
    api<PersonDetail>(`/people/${id}`).then((r) => r.data),
    api<DeliveredItem[]>(`/people/${id}/delivery/items?from=${from}&to=${to}`).then((r) => r.data),
  ]).catch(refuse);

  const dated = items.filter((item) => item.late !== null);
  const onTime = dated.filter((item) => item.late === false);

  return (
    <div className="space-y-5">
      <Breadcrumb
        locale={locale}
        items={[
          { label: t("nav.people"), href: "/people" },
          { label: person.name, href: `/people/${id}` },
          { label: t("delivery.title") },
        ]}
      />

      <PageHeader
        title={t("delivery.itemsTitle")}
        description={t("delivery.window", {
          name: person.name,
          from: formatDate(from, "UTC", locale),
          to: formatDate(to, "UTC", locale),
        })}
      />

      <PageBody>
        {items.length === 0 ? (
          <EmptyState title={t("delivery.empty.title")} description={t("delivery.empty.body")} />
        ) : (
          <Panel
            id="delivered"
            title={t.plural("delivery.itemsCount", items.length)}
            description={
              dated.length === 0
                ? t("delivery.noDated")
                : t("delivery.onTime", { onTime: onTime.length, dated: dated.length })
            }
            bleed
          >
            <DataTable caption={t("delivery.itemsTitle")}>
              <THead>
                <Tr>
                  <Th>{t("delivery.col.item")}</Th>
                  <Th width="w-32">{t("delivery.col.finished")}</Th>
                  <Th width="w-32">{t("delivery.col.due")}</Th>
                  <Th width="w-28">{t("delivery.col.result")}</Th>
                </Tr>
              </THead>
              <TBody>
                {items.map((item) => (
                  <Tr key={item.work_item_id}>
                    <Td>
                      <Link href={`/work/${item.reference}`} className="group flex min-w-0 items-baseline gap-2">
                        <span className="w-16 shrink-0 font-mono text-caption text-n-500">{item.reference}</span>
                        <span className="min-w-0 truncate font-medium text-n-900 group-hover:underline">{item.title}</span>
                      </Link>
                    </Td>
                    <Td muted>{formatDate(item.completed_at, me.user.timezone, locale)}</Td>
                    <Td muted>{item.due_at ? formatDate(item.due_at, me.user.timezone, locale) : t("delivery.noDue")}</Td>
                    <Td>
                      {item.late === null ? (
                        <Badge>{t("delivery.result.undated")}</Badge>
                      ) : item.late ? (
                        <Badge tone="warning">{t("delivery.result.late")}</Badge>
                      ) : (
                        <Badge tone="success" icon="check">{t("delivery.result.onTime")}</Badge>
                      )}
                    </Td>
                  </Tr>
                ))}
              </TBody>
            </DataTable>
          </Panel>
        )}
      </PageBody>
    </div>
  );
}
