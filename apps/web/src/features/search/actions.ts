"use server";

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
export async function search(query: string): Promise<SearchOutcome> {
  const terms = query.trim();

  // The API refuses anything shorter, and asking it to say so on every
  // keystroke wastes a round trip to learn what we already know.
  if (terms.length < 2) {
    return { results: [], error: null };
  }

  try {
    const { data } = await api<SearchHit[]>(
      `/search?q=${encodeURIComponent(terms)}&limit=15`,
      { revalidate: false },
    );

    return { results: data, error: null };
  } catch (error) {
    if (error instanceof ApiRequestError) {
      // 429 has a meaning worth showing plainly: the palette fires on
      // keystrokes, and "slow down" is not the same as "nothing found".
      return {
        results: [],
        error:
          error.status === 429
            ? "Too many searches just now. Try again in a moment."
            : error.error.message,
      };
    }

    return { results: [], error: "We could not reach the server. Please try again." };
  }
}
