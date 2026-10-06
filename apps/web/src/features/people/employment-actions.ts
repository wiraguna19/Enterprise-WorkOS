"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/** Recording somebody's employment facts (ADR 0063). Never your own: the API refuses it. */
export async function updateEmployment(
  membershipId: string,
  input: {
    job_title: string;
    job_level: string | null;
    hired_at: string | null;
    employment_type: string;
    weekly_capacity_hours: number;
  },
): Promise<{ error: string | null }> {
  try {
    await api(`/people/${membershipId}/employment`, { method: "PATCH", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}
