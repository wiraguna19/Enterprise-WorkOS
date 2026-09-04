"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";
import type { ReportExport } from "./types";

export type ExportRequestState = { error: string | null; export?: ReportExport };

/**
 * Ask for a file. Not: receive one.
 *
 * ADR 0011 makes an export a ROW rather than a response — the request answers
 * 202 with something to watch, and a worker builds the file with the
 * requester's own visibility. So this returns the row, and the panel that
 * called it waits.
 *
 * The parameters are the ones the page is currently showing. An export that
 * silently used a different window than the screen above it would be the worst
 * kind of wrong: it opens, it looks right, and its numbers disagree with the
 * page the reader exported it from.
 */
export async function requestExport(
  reportKey: string,
  format: string,
  parameters: Record<string, string>,
): Promise<ExportRequestState> {
  const query = new URLSearchParams({ ...parameters, format });

  try {
    const { data } = await api<ReportExport>(`/reports/${reportKey}/export?${query}`, {
      method: "POST",
    });

    revalidatePath("/reports");

    return { error: null, export: data };
  } catch (error) {
    if (error instanceof ApiRequestError) {
      // The server's own words, including the rate limit's. Five exports an
      // hour per organization is a real rule (docs/05 §6) and "try again
      // later" would hide which rule was hit.
      return { error: error.error.message };
    }

    return { error: "We could not reach the server. Please try again." };
  }
}

/** The exports this person has asked for, newest first, and the formats on offer. */
export async function listExports(): Promise<{ exports: ReportExport[]; formats: string[] }> {
  try {
    const response = await api<ReportExport[]>("/reports/exports");

    return {
      exports: response.data,
      // From the server, never a copy kept here: a client with its own list
      // offers formats that do not exist yet, or hides ones that do.
      formats: (response.meta?.formats as string[] | undefined) ?? ["csv"],
    };
  } catch {
    return { exports: [], formats: ["csv"] };
  }
}

/**
 * A short-lived URL for one file.
 *
 * Fetched at the moment of the click rather than rendered into the list: the
 * URL expires in five minutes, and a page left open for ten would offer links
 * that all fail. It is also only ever the requester's own — the file was built
 * with their visibility, so handing it to a colleague hands over rows that
 * colleague may not be able to see anywhere else.
 */
export async function downloadUrl(exportId: string): Promise<{ url: string | null; error: string | null }> {
  try {
    const { data } = await api<{ url: string }>(`/reports/exports/${exportId}/download`);

    return { url: data.url, error: null };
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { url: null, error: error.error.message };
    }

    return { url: null, error: "We could not reach the server. Please try again." };
  }
}
