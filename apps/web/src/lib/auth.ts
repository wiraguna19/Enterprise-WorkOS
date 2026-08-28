import { redirect } from "next/navigation";
import { api, ApiRequestError } from "./api";

/**
 * The bootstrap read every authenticated page performs.
 *
 * Returns identity, tenant, and the permission set — the last of which is what
 * lets the UI render controls without inventing its own copy of the rules
 * (docs/07 §4). The server remains the only authority; this describes its
 * decision.
 */

export type CurrentUser = {
  user: { id: string; name: string; email: string; timezone: string };
  membership: { id: string; status: string; job_title: string | null };
  organization: { id: string; name: string; slug: string };
  permissions: string[];
};

export async function requireUser(): Promise<CurrentUser> {
  try {
    const { data } = await api<CurrentUser>("/auth/me", {
      tags: ["session"],
      revalidate: false,
    });

    return data;
  } catch (error) {
    if (error instanceof ApiRequestError && (error.status === 401 || error.status === 403)) {
      redirect("/login");
    }

    throw error;
  }
}
