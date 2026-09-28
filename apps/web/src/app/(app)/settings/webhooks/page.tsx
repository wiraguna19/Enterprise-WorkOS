import Link from "next/link";
import { notFound } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { WebhookEditor } from "@/features/workflow/WebhookEditor";
import {
  STATUS_WORDS,
  type DeliveryStatus,
  type WebhookDelivery,
  type WebhookEndpoint,
} from "@/features/workflow/webhooks";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";

/**
 * Webhooks (docs/02 §7, docs/10 Phase 7, ADR 0048).
 *
 * The endpoints and the screen arrive together, and so does the rule builder's
 * "Send a webhook" action that is their only producer besides the test button
 * here — an endpoint nothing can send to would be a write path with no reader.
 *
 * `?endpoint=ID` shows that endpoint's latest deliveries under the list. A
 * link, not a toggle, so "look at these" can be pasted to whoever runs the
 * receiver.
 */
const TONE: Record<DeliveryStatus, "neutral" | "info" | "success" | "danger"> = {
  pending: "info",
  delivered: "success",
  abandoned: "danger",
  refused: "neutral",
};

export default async function WebhooksPage({
  searchParams,
}: {
  searchParams: Promise<{ endpoint?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  // Refused, not emptied: "no endpoints" would be a confident statement about
  // somebody else's organization.
  if (!me.permissions.includes("webhook.manage")) notFound();

  const endpoints = await api<WebhookEndpoint[]>("/webhook-endpoints").then((r) => r.data);
  const selected = endpoints.find((endpoint) => endpoint.id === params.endpoint);

  const deliveries =
    selected === undefined
      ? null
      : await api<WebhookDelivery[]>(`/webhook-endpoints/${selected.id}/deliveries`).then(
          (r) => r.data,
        );

  return (
    <div className="space-y-5">
      <PageHeader
        title="Webhooks"
        description="Where automation rules may send this organization's events. Rules choose among these; they cannot add an address."
      />

      <PageBody>
        <div className="space-y-4">
          <WebhookEditor endpoints={endpoints} showingDeliveriesFor={selected?.id} />

          {selected !== undefined && deliveries !== null && (
            <Panel
              id="deliveries"
              title={`Deliveries to ${selected.name}`}
              description={
                deliveries.length === 0
                  ? "Nothing has been sent yet. “Send a test” sends one now."
                  : "The latest fifty, newest first. What the receiver answered is not kept — only its status code."
              }
              actions={
                <Link href="/settings/webhooks" className="text-body-sm text-a-700 underline">
                  Close
                </Link>
              }
              bleed
            >
              {deliveries.length > 0 && (
                <DataTable caption={`Latest deliveries to ${selected.name}`}>
                  <THead>
                    <Tr>
                      <Th>When</Th>
                      <Th>Event</Th>
                      <Th>Status</Th>
                      <Th align="right">Tries</Th>
                      <Th>Last answer</Th>
                    </Tr>
                  </THead>
                  <TBody>
                    {deliveries.map((delivery) => (
                      <Tr key={delivery.id}>
                        <Td muted>{formatDateTime(delivery.created_at, me.user.timezone)}</Td>
                        <Td>
                          <code className="font-mono text-micro">{delivery.event}</code>
                        </Td>
                        <Td>
                          <Badge tone={TONE[delivery.status]}>{delivery.status}</Badge>
                          <span className="block text-caption text-n-500">
                            {STATUS_WORDS[delivery.status]}
                            {delivery.status === "pending" &&
                              delivery.next_attempt_at !== null &&
                              ` — next try ${formatDateTime(delivery.next_attempt_at, me.user.timezone)}`}
                          </span>
                        </Td>
                        <Td align="right" muted>
                          {delivery.attempts}
                        </Td>
                        <Td muted>
                          {delivery.last_status_code !== null && (
                            <span className="font-mono">{delivery.last_status_code} </span>
                          )}
                          {delivery.last_error ?? (delivery.status === "delivered" ? "Accepted" : "—")}
                        </Td>
                      </Tr>
                    ))}
                  </TBody>
                </DataTable>
              )}
            </Panel>
          )}
        </div>
      </PageBody>
    </div>
  );
}
