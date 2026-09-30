"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";
import type { WebhookDelivery } from "./webhooks";

/**
 * Administering webhook endpoints (ADR 0048).
 *
 * Two of these return a SECRET, and they are the only two places in the
 * product that ever do. The screen shows it once, beside a sentence saying so;
 * nothing stores it on this side.
 */
export type EndpointResult = { error: string | null };

function refresh(): void {
  revalidatePath("/settings/webhooks");
  // The rule builder offers endpoints by name, so a rename or a switch-off
  // must reach the forms that list them.
  revalidatePath("/settings/rules");
}

export async function registerEndpoint(input: {
  name: string;
  url: string;
  events: string[];
}): Promise<EndpointResult & { secret?: string }> {
  let secret: string;

  try {
    const { data } = await api<{ secret: string }>("/webhook-endpoints", {
      method: "POST",
      body: { name: input.name, url: input.url, events: input.events },
    });

    secret = data.secret;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null, secret };
}

export async function saveEndpoint(
  id: string,
  input: { name: string; url: string; events: string[] },
): Promise<EndpointResult> {
  try {
    await api(`/webhook-endpoints/${id}`, {
      method: "PATCH",
      body: { name: input.name, url: input.url, events: input.events },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function setEndpointActive(id: string, active: boolean): Promise<EndpointResult> {
  try {
    await api(`/webhook-endpoints/${id}/active`, { method: "POST", body: { active } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

/** A new secret, effective at once. The old one stops signing immediately. */
export async function rotateEndpointSecret(
  id: string,
): Promise<EndpointResult & { secret?: string }> {
  let secret: string;

  try {
    const { data } = await api<{ secret: string }>(`/webhook-endpoints/${id}/secret`, {
      method: "POST",
    });

    secret = data.secret;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null, secret };
}

export async function deleteEndpoint(id: string): Promise<EndpointResult> {
  try {
    await api(`/webhook-endpoints/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

/** Send a `ping` now and report what happened to it. */
export async function testEndpoint(
  id: string,
): Promise<EndpointResult & { delivery?: WebhookDelivery }> {
  let delivery: WebhookDelivery;

  try {
    const { data } = await api<WebhookDelivery>(`/webhook-endpoints/${id}/test`, {
      method: "POST",
    });

    delivery = data;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null, delivery };
}
