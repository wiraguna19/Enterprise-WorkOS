import type { MessageKey } from "@/i18n/messages/en";
import type { Translator } from "@/i18n/translate";

/**
 * Webhook endpoints and deliveries, as `WebhookEndpointController` sends them
 * (ADR 0048). Hand-written, and every field is read by the settings screen.
 */
export type WebhookEndpoint = {
  id: string;
  name: string;
  /** Shown only to `webhook.manage`: an address is often a credential. */
  url: string;
  is_active: boolean;
  /** Consecutive ABANDONED deliveries, not failed attempts. */
  failure_count: number;
  /** Why it switched itself off, when it did. */
  disabled_reason: string | null;
  /**
   * Events it receives every time, without a rule (ADR 0048) — from the list
   * the API serves as `meta.subscribable`.
   */
  events: string[];
  /** The rules that send to it — the ones a delete would break. */
  rules: string[];
  created_at: string;
};

export type DeliveryStatus = "pending" | "delivered" | "abandoned" | "refused";

export type WebhookDelivery = {
  id: string;
  event: string;
  status: DeliveryStatus;
  attempts: number;
  last_status_code: number | null;
  /** The reason only — the receiver's answer is never stored. */
  last_error: string | null;
  next_attempt_at: string | null;
  delivered_at: string | null;
  created_at: string;
};

/** A delivery status's short name, for the badge. */
export function statusName(status: DeliveryStatus, t: Translator): string {
  return t(`hook.status.${status}` as MessageKey);
}

/** What each status means, in the words of somebody on the receiving end. */
export function statusWords(status: DeliveryStatus, t: Translator): string {
  return t(`hook.statusWords.${status}` as MessageKey);
}

const NAMED_EVENTS = new Set([
  "work_item.created",
  "work_item.assigned",
  "work_item.status_changed",
  "approval.decided",
  "schedule.due_soon",
  "schedule.overdue",
]);

/**
 * What an event is, in the words a person setting up a receiver would use. An
 * event the API starts offering before this list knows it is shown by its key
 * rather than hidden: the key is what the receiver will see anyway.
 */
export function eventWords(event: string, t: Translator): string {
  return NAMED_EVENTS.has(event) ? t(`hook.event.${event}` as MessageKey) : event;
}
