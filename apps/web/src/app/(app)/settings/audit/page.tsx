import { notFound } from "next/navigation";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { AuditFilters } from "@/features/audit/AuditFilters";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";

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

  const { data: entries } = await api<Entry[]>(`/audit-logs?${query.toString()}`);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Audit log"
        description="Who did what, and when. Written by the system; nothing here can be edited."
      />

      <AuditFilters event={params.event ?? ""} since={params.since ?? ""} />

      {entries.length === 0 ? (
        <EmptyState
          title="Nothing matches"
          description="The log records sign-ins, invitations, role changes and exports. An empty result here means no such event in this organization — not that nothing was recorded."
        />
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {entries.map((entry) => (
            <li key={entry.id} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-2">
              <span className="w-40 shrink-0 text-caption tabular-nums text-n-500">
                {formatDateTime(entry.occurred_at, me.user.timezone)}
              </span>

              <span className="min-w-0 flex-1">
                <span className="font-mono text-body-sm text-n-900">{entry.event}</span>{" "}
                {/* The address AS IT WAS. The snapshot is the point: resolving
                    the name today would rewrite history every time somebody
                    changed theirs, and would say nothing about an account since
                    deleted. */}
                <span className="text-body-sm text-n-500">{entry.actor || "the system"}</span>
                {Object.keys(entry.metadata).length > 0 && (
                  <span className="mt-0.5 block break-words font-mono text-micro text-n-500">
                    {JSON.stringify(entry.metadata)}
                  </span>
                )}
              </span>

              <span className="w-32 shrink-0 text-right font-mono text-micro text-n-500">
                {entry.ip_address ?? "—"}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
