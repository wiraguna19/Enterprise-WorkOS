"use server";

import { revalidatePath } from "next/cache";
import type { LeavePolicy, LeaveType } from "./types";
import { api, describeApiError } from "@/lib/api";

/**
 * Writing the organization's leave rules (ADR 0063). Every one answers with an
 * error sentence or null, like the other settings screens.
 */
export type LeaveActionResult = { error: string | null };

async function run(call: () => Promise<unknown>): Promise<LeaveActionResult> {
  try {
    await call();
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/leave");

  return { error: null };
}

export async function applyLeavePreset(preset: string): Promise<LeaveActionResult> {
  return run(() => api("/leave/settings/preset", { method: "POST", body: { preset } }));
}

export async function saveLeavePolicy(
  policy: Omit<LeavePolicy, "preset" | "updated_at">,
): Promise<LeaveActionResult> {
  return run(() => api("/leave/settings/policy", { method: "PUT", body: policy }));
}

export type LeaveTypeInput = Omit<LeaveType, "id" | "key" | "sort_order">;

export async function createLeaveType(input: LeaveTypeInput & { key: string }): Promise<LeaveActionResult> {
  return run(() => api("/leave/types", { method: "POST", body: input }));
}

export async function updateLeaveType(id: string, input: Partial<LeaveTypeInput>): Promise<LeaveActionResult> {
  return run(() => api(`/leave/types/${id}`, { method: "PATCH", body: input }));
}

export async function addHoliday(input: {
  on_date: string;
  name: string;
  kind: "public" | "collective";
}): Promise<LeaveActionResult> {
  return run(() => api("/leave/holidays", { method: "POST", body: input }));
}

export async function removeHoliday(id: string): Promise<LeaveActionResult> {
  return run(() => api(`/leave/holidays/${id}`, { method: "DELETE" }));
}
