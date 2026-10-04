"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";
import type { AudienceType } from "./types";

export type AnnouncementResult = { error: string | null; requestId?: string; id?: string };

export type AnnouncementInput = {
  title: string;
  body: string;
  pinned: boolean;
  requires_acknowledgement: boolean;
  /**
   * An instant, or null for "does not expire". Worked out in the browser from
   * the chosen day, so the reader's own midnight is the one that counts.
   */
  expires_at: string | null;
};

function failure(error: unknown): AnnouncementResult {
  if (error instanceof ApiRequestError) {
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

export async function publishAnnouncement(
  input: AnnouncementInput & { audience_type: AudienceType; audience_id: string | null },
): Promise<AnnouncementResult> {
  let id: string;

  try {
    const { data } = await api<{ id: string }>("/announcements", {
      method: "POST",
      body: {
        title: input.title,
        body: input.body,
        audience_type: input.audience_type,
        audience_id: input.audience_type === "organization" ? null : input.audience_id,
        pinned: input.pinned,
        requires_acknowledgement: input.requires_acknowledgement,
        expires_at: input.expires_at,
      },
    });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/announcements");

  return { error: null, id };
}

/** The words and the flags. Never the audience (ADR 0061). */
export async function updateAnnouncement(
  id: string,
  input: AnnouncementInput,
): Promise<AnnouncementResult> {
  try {
    await api(`/announcements/${id}`, {
      method: "PATCH",
      body: {
        title: input.title,
        body: input.body,
        pinned: input.pinned,
        requires_acknowledgement: input.requires_acknowledgement,
        expires_at: input.expires_at,
      },
    });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/announcements");
  revalidatePath(`/announcements/${id}`);

  return { error: null, id };
}

export async function removeAnnouncement(id: string): Promise<AnnouncementResult> {
  try {
    await api(`/announcements/${id}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/announcements");

  return { error: null };
}

/**
 * Mark what was on screen as read. Not awaited by anything the reader sees,
 * and never revalidated: the "New" marks stay for this visit, which is when
 * they are useful.
 */
export async function markAnnouncementsRead(ids: string[]): Promise<void> {
  if (ids.length === 0) return;

  await api("/announcements/read", { method: "POST", body: { ids } }).catch(() => undefined);
}

export async function acknowledgeAnnouncement(id: string): Promise<AnnouncementResult> {
  try {
    await api(`/announcements/${id}/acknowledge`, { method: "POST" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/announcements");
  revalidatePath(`/announcements/${id}`);

  return { error: null, id };
}
