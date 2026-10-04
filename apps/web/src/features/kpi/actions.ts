"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type KpiResult = { error: string | null; requestId?: string; id?: string };

function failure(error: unknown): KpiResult {
  if (error instanceof ApiRequestError) {
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

export type NewKpi = {
  name: string;
  description: string;
  subject_type: string;
  subject_id: string;
  source: string;
  unit: string;
  direction: "higher" | "lower";
  target: number;
  period: string;
};

export async function createKpi(input: NewKpi): Promise<KpiResult> {
  let id: string;

  try {
    const { data } = await api<{ id: string }>("/kpis", { method: "POST", body: input });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/kpis");

  return { error: null, id };
}

/** Never the subject, source or period: that would rewrite the history (ADR 0062). */
export async function updateKpi(
  id: string,
  input: { name: string; description: string; target: number; unit?: string; direction?: "higher" | "lower" },
): Promise<KpiResult> {
  try {
    await api(`/kpis/${id}`, { method: "PATCH", body: input });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/kpis");
  revalidatePath(`/kpis/${id}`);

  return { error: null, id };
}

/** Archived, not deleted: the API keeps the history of a dropped target. */
export async function archiveKpi(id: string): Promise<KpiResult> {
  try {
    await api(`/kpis/${id}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/kpis");

  return { error: null };
}

export async function recordKpiValue(
  id: string,
  input: { period_start: string; value: number; note: string },
): Promise<KpiResult> {
  try {
    await api(`/kpis/${id}/entries`, { method: "PUT", body: input });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/kpis");
  revalidatePath(`/kpis/${id}`);

  return { error: null, id };
}
