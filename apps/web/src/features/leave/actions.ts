"use server";

import { revalidatePath } from "next/cache";
import type { LeaveQuote } from "./types";
import { api, describeApiError } from "@/lib/api";

/** Asking for time off and deciding (ADR 0063). */
export type LeaveResult = { error: string | null };

type Ask = { leave_type_id: string; starts_on: string; ends_on: string; half_day: "am" | "pm" | null };

export async function quoteLeave(ask: Ask): Promise<{ quote: LeaveQuote | null; error: string | null }> {
  const query = new URLSearchParams({ leave_type_id: ask.leave_type_id, starts_on: ask.starts_on, ends_on: ask.ends_on });

  if (ask.half_day) query.set("half_day", ask.half_day);

  try {
    const { data } = await api<LeaveQuote>(`/leave/quote?${query}`);

    return { quote: data, error: null };
  } catch (error) {
    return { quote: null, error: describeApiError(error).error };
  }
}

async function run(call: () => Promise<unknown>): Promise<LeaveResult> {
  try {
    await call();
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/leave");
  revalidatePath("/leave/approvals");
  revalidatePath("/leave/all");

  return { error: null };
}

export async function submitLeave(ask: Ask & { reason: string }): Promise<LeaveResult> {
  return run(() => api("/leave/requests", { method: "POST", body: ask }));
}

export async function cancelLeave(id: string): Promise<LeaveResult> {
  return run(() => api(`/leave/requests/${id}/cancel`, { method: "POST" }));
}

export async function decideLeave(id: string, verdict: "approve" | "reject", note: string): Promise<LeaveResult> {
  // Two literal paths rather than one with the verdict spliced in: the
  // reachability guard reads paths as written, and so does whoever greps.
  return run(() =>
    verdict === "approve"
      ? api(`/leave/requests/${id}/approve`, { method: "POST", body: { note } })
      : api(`/leave/requests/${id}/reject`, { method: "POST", body: { note } }),
  );
}
