import { notFound } from "next/navigation";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { AuditFilters } from "@/features/audit/AuditFilters";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
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
        title="Audit log"
        description="Who did what, and when. Written by the system; nothing here can be edited."
      />

      <PageBody>
        <AuditFilters event={params.event ?? ""} since={params.since ?? ""} />

        {entries.length === 0 ? (
          <EmptyState
            title="Nothing matches"
            description="The log records sign-ins, invitations, role changes and exports. An empty result here means no such event in this organization — not that nothing was recorded."
          />
        ) : (
          <Panel
            id="entries"
            title="Events"
            description={
              // Where the record ENDS (ADR 0021). An empty result for last
              // March otherwise reads as "nothing happened in March", which is
              // indistinguishable from "March was dropped".
              retention?.covers_since
                ? `${entries.length} shown. This log keeps ${retention.months} months — nothing before ${formatDate(retention.covers_since, me.user.timezone)} exists to be found, so an empty month is not an answer about what happened.`
                : `${entries.length} shown, newest first`
            }
            bleed
          >
            <DataTable caption="Audit log entries">
              <THead>
                <Tr>
                  <Th width="w-44">When</Th>
                  <Th width="w-56">Event</Th>
                  <Th>Actor</Th>
                  <Th>Detail</Th>
                  <Th align="right">Address</Th>
                </Tr>
              </THead>
              <TBody>
                {entries.map((entry) => (
                  <Tr key={entry.id}>
                    <Td muted>
                      <span className="whitespace-nowrap tabular-nums">
                        {formatDateTime(entry.occurred_at, me.user.timezone)}
                      </span>
                    </Td>
                    <Td>
                      <span className="font-mono text-body-sm">{entry.event}</span>
                    </Td>
                    {/* The address AS IT WAS. The snapshot is the point:
                        resolving the name today would rewrite history every
                        time somebody changed theirs, and would say nothing at
                        all about an account since deleted. */}
                    <Td muted>{entry.actor || "the system"}</Td>
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
