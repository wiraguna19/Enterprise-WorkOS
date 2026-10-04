"use server";

import { requestLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";

export type SearchHit = {
  type: "work_item" | "project" | "person";
  id: string;
  title: string;
  subtitle: string | null;
  reference: string | null;
  matched_on: string;
  rank: number;
};

export type SearchOutcome =
  | { results: SearchHit[]; error: null }
  | { results: []; error: string };

/**
 * A Server Action rather than a browser fetch, for the same reason every other
 * write is one: the session token lives in an HttpOnly cookie and is attached
 * server-side, so the browser never holds a bearer token (docs/06 §1).
 *
 * Nothing is cached. Search results depend on who is asking — the API applies
 * each record's visibility rule (docs/06 §2) — and a cache keyed on the query
 * alone would serve one person's permitted results to another.
 */
export type SearchCategory = SearchHit["type"];

export type RecentSearch = { id: string; query: string; type: SearchCategory | null };

export async function search(query: string, category: SearchCategory | null = null): Promise<SearchOutcome> {
  const terms = query.trim();

  // The API refuses anything shorter, and asking it to say so on every
  // keystroke wastes a round trip to learn what we already know.
  if (terms.length < 2) {
    return { results: [], error: null };
  }

  try {
    const { data } = await api<SearchHit[]>(
      `/search?q=${encodeURIComponent(terms)}&limit=15${category === null ? "" : `&types=${category}`}`,
      { revalidate: 0 },
    );

    return { results: data, error: null };
  } catch (error) {
    // The browser's copy of the person's language: a server action has no
    // `/auth/me` in hand, and the app layout keeps the cookie in step (ADR 0060).
    const t = translator(await requestLocale());

    if (error instanceof ApiRequestError) {
      // 429 has a meaning worth showing plainly: the palette fires on
      // keystrokes, and "slow down" is not the same as "nothing found".
      return {
        results: [],
        error:
          error.status === 429
            ? t("palette.tooMany")
            : error.error.message,
      };
    }

    return { results: [], error: t("common.unreachable") };
  }
}

/**
 * This person's recent searches, newest first. A failure is an empty list:
 * the palette still searches without its memory, and a red error before a
 * single key is pressed would be about something nobody asked for.
 */
export async function recentSearches(): Promise<RecentSearch[]> {
  // `revalidate: 0`, never `false`: in Next.js `false` caches the answer
  // forever, and a palette offering searches already forgotten — with ids that
  // no longer exist, so × removes nothing — is exactly what that produced.
  return api<RecentSearch[]>("/me/recent-searches", { revalidate: 0 })
    .then((r) => r.data)
    .catch(() => []);
}

/**
 * Remember a search that led somewhere. Called when a result is opened, and
 * never awaited by the navigation: losing one entry of a convenience is not a
 * reason to hold somebody on the palette.
 */
export async function rememberSearch(query: string, category: SearchCategory | null): Promise<void> {
  const terms = query.trim();

  if (terms.length < 2) return;

  await api("/me/recent-searches", {
    method: "POST",
    body: category === null ? { query: terms } : { query: terms, type: category },
  }).catch(() => undefined);
}

export async function forgetSearch(id: string): Promise<void> {
  await api(`/me/recent-searches/${encodeURIComponent(id)}`, { method: "DELETE" }).catch(() => undefined);
}

export async function forgetAllSearches(): Promise<void> {
  await api("/me/recent-searches", { method: "DELETE" }).catch(() => undefined);
}
