import { notFound } from "next/navigation";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { AuditFilters } from "@/features/audit/AuditFilters";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDate, formatDateTime } from "@/lib/format";

/**
 * The security audit log (ADR 0019).
 *
 * Written since Phase 1 and read by nothing until now. Every question it exists
 * to answer — who let that person in, who changed that role, who tried to sign
 * in as somebody and failed — has been unanswerable through the product for
 * seven phases.
 *
 * Three filters, matching the three axes the table is indexed on. A filter the
 * index cannot serve turns a partitioned table into a sequential scan, and a
 * screen that offers it times out in the one month somebody needs it most.
 */
type Entry = {
  id: string;
  event: string;
  actor: string;
  target_type: string | null;
  target_id: string | null;
  metadata: Record<string, unknown>;
  ip_address: string | null;
  occurred_at: string;
};

export default async function AuditLogPage({
  searchParams,
}: {
  searchParams: Promise<{ event?: string; since?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  if (!me.permissions.includes("audit_log.view")) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const query = new URLSearchParams({ limit: "100" });

  if (params.event) query.set("event", params.event);
  if (params.since) query.set("since", params.since);

  const { data: entries, meta } = await api<Entry[]>(`/audit-logs?${query.toString()}`);

  // Where the record ENDS (ADR 0021). Partitions past the retention window are
  // dropped monthly, so an empty result for last March reads as "nothing
  // happened in March" — the most dangerous sentence an audit log can imply,
  // and indistinguishable from "March was dropped" unless the screen says so.
  const retention = (meta?.retention ?? null) as { months: number | null; covers_since: string | null } | null;

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.audit.label")}
        description={t("audit.description")}
      />

      <PageBody>
        <AuditFilters event={params.event ?? ""} since={params.since ?? ""} />

        {entries.length === 0 ? (
          <EmptyState
            title={t("audit.empty.title")}
            description={t("audit.empty.body")}
          />
        ) : (
          <Panel
            id="entries"
            title={t("audit.events")}
            description={
              // Where the record ENDS (ADR 0021). An empty result for last
              // March otherwise reads as "nothing happened in March", which is
              // indistinguishable from "March was dropped".
              retention?.covers_since
                ? t("audit.retention", {
                    count: entries.length,
                    months: retention.months ?? "",
                    date: formatDate(retention.covers_since, me.user.timezone, locale),
                  })
                : t("audit.shown", { count: entries.length })
            }
            bleed
          >
            <DataTable caption={t("audit.caption")}>
              <THead>
                <Tr>
                  <Th width="w-44">{t("audit.col.when")}</Th>
                  <Th width="w-56">{t("audit.col.event")}</Th>
                  <Th>{t("audit.col.actor")}</Th>
                  <Th>{t("audit.col.detail")}</Th>
                  <Th align="right">{t("sess.col.address")}</Th>
                </Tr>
              </THead>
              <TBody>
                {entries.map((entry) => (
                  <Tr key={entry.id}>
                    <Td muted>
                      <span className="whitespace-nowrap tabular-nums">
                        {formatDateTime(entry.occurred_at, me.user.timezone, locale)}
                      </span>
                    </Td>
                    <Td>
                      <span className="font-mono text-body-sm">{entry.event}</span>
                    </Td>
                    {/* The address AS IT WAS. The snapshot is the point:
                        resolving the name today would rewrite history every
                        time somebody changed theirs, and would say nothing at
                        all about an account since deleted. */}
                    <Td muted>{entry.actor || t("audit.system")}</Td>
                    <Td muted>
                      {Object.keys(entry.metadata).length === 0 ? (
                        ""
                      ) : (
                        <span className="block max-w-xl break-words font-mono text-micro">
                          {JSON.stringify(entry.metadata)}
                        </span>
                      )}
                    </Td>
                    <Td align="right" muted>
                      <span className="font-mono text-micro">{entry.ip_address ?? "—"}</span>
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
