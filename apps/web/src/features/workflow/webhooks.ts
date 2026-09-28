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

/** What each status means, in the words of somebody on the receiving end. */
export const STATUS_WORDS: Record<DeliveryStatus, string> = {
  pending: "Waiting to be sent, or to be tried again",
  delivered: "The receiver accepted it",
  abandoned: "Every retry failed; it will not be sent again",
  refused: "Never sent — the endpoint was off, or its address is not allowed",
};
