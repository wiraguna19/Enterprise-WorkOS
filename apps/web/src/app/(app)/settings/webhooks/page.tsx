import Link from "next/link";
import { notFound } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { WebhookEditor } from "@/features/workflow/WebhookEditor";
import {
  statusName,
  statusWords,
  type DeliveryStatus,
  type WebhookDelivery,
  type WebhookEndpoint,
} from "@/features/workflow/webhooks";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
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

  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const { data: endpoints, meta } = await api<WebhookEndpoint[]>("/webhook-endpoints");
  // Served by the API (ADR 0048): the events something in the product
  // actually emits. An older API without it offers no subscriptions rather
  // than a guessed list.
  const subscribable = Array.isArray(meta?.subscribable) ? (meta.subscribable as string[]) : [];
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
        title={t("settings.webhooks.label")}
        description={t("hook.page.description")}
      />

      <PageBody>
        <div className="space-y-4">
          <WebhookEditor
            endpoints={endpoints}
            subscribable={subscribable}
            showingDeliveriesFor={selected?.id}
          />

          {selected !== undefined && deliveries !== null && (
            <Panel
              id="deliveries"
              title={t("hook.deliveries.title", { name: selected.name })}
              description={
                deliveries.length === 0
                  ? t("hook.deliveries.none")
                  : t("hook.deliveries.some")
              }
              actions={
                <Link href="/settings/webhooks" className="text-body-sm text-a-700 underline">
                  {t("hook.close")}
                </Link>
              }
              bleed
            >
              {deliveries.length > 0 && (
                <DataTable caption={t("hook.deliveries.caption", { name: selected.name })}>
                  <THead>
                    <Tr>
                      <Th>{t("audit.col.when")}</Th>
                      <Th>{t("audit.col.event")}</Th>
                      <Th>{t("hook.col.status")}</Th>
                      <Th align="right">{t("hook.col.tries")}</Th>
                      <Th>{t("hook.col.lastAnswer")}</Th>
                    </Tr>
                  </THead>
                  <TBody>
                    {deliveries.map((delivery) => (
                      <Tr key={delivery.id}>
                        <Td muted>{formatDateTime(delivery.created_at, me.user.timezone, locale)}</Td>
                        <Td>
                          <code className="font-mono text-micro">{delivery.event}</code>
                        </Td>
                        <Td>
                          <Badge tone={TONE[delivery.status]}>{statusName(delivery.status, t)}</Badge>
                          <span className="block text-caption text-n-500">
                            {statusWords(delivery.status, t)}
                            {delivery.status === "pending" &&
                              delivery.next_attempt_at !== null &&
                              t("hook.nextTry", {
                                when: formatDateTime(delivery.next_attempt_at, me.user.timezone, locale),
                              })}
                          </span>
                        </Td>
                        <Td align="right" muted>
                          {delivery.attempts}
                        </Td>
                        <Td muted>
                          {delivery.last_status_code !== null && (
                            <span className="font-mono">{delivery.last_status_code} </span>
                          )}
                          {delivery.last_error ?? (delivery.status === "delivered" ? t("hook.accepted") : "—")}
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
