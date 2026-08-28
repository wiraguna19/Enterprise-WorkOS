"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type FeedResult =
  | { url: string; error: null }
  | { url: null; error: string };

/**
 * Issue a subscription URL for an external calendar.
 *
 * The URL comes back exactly once. Only its digest is stored (docs/06 §1), so
 * there is no second chance to read it — which is why the caller must show it
 * immediately rather than storing it for a later screen, and why regenerating
 * is the answer to "I lost it" rather than a recovery flow that cannot exist.
 */
export async function createFeed(): Promise<FeedResult> {
  try {
    const { data } = await api<{ url: string }>("/calendar/feed", { method: "POST" });

    revalidatePath("/calendar");

    return { url: data.url, error: null };
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { url: null, error: error.error.message };
    }

    return { url: null, error: "We could not reach the server. Please try again." };
  }
}

/** Revoking is deleting: the old URL stops working immediately. */
export async function revokeFeed(): Promise<{ error: string | null }> {
  try {
    await api("/calendar/feed", { method: "DELETE" });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  revalidatePath("/calendar");

  return { error: null };
}
