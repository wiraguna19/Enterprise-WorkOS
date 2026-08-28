"use client";

import { useEffect, useRef, useState, useTransition } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * Filtering the directory (docs/08 §2).
 *
 * The query lives in the URL, not in component state, so a filtered directory
 * is a thing you can send to someone. The input keeps its own value while you
 * type — the URL trails it by a beat — because re-rendering the field from a
 * value that arrives after a round trip is how a search box eats keystrokes.
 *
 * Filtering happens on the SERVER: the page holds one page of people, not all
 * of them, so filtering the array in the browser would search only what
 * happened to have been fetched and quietly miss the rest.
 */
export function PeopleSearch({ initialQuery }: { initialQuery: string }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const [value, setValue] = useState(initialQuery);
  const [isPending, startTransition] = useTransition();

  // The first render must not navigate: it would replace the URL the user just
  // arrived on, and turn a shared link into a history entry they cannot leave.
  const mounted = useRef(false);

  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;

      return;
    }

    const timer = setTimeout(() => {
      const params = new URLSearchParams(searchParams);
      const trimmed = value.trim();

      // The API rejects a one-character query (min:2); below that the field is
      // treated as empty rather than sent and refused.
      if (trimmed.length >= 2) {
        params.set("q", trimmed);
      } else {
        params.delete("q");
      }

      startTransition(() => {
        router.replace(`${pathname}?${params}`, { scroll: false });
      });
    }, 250);

    return () => clearTimeout(timer);
  }, [value, pathname, router, searchParams]);

  return (
    <div className="relative">
      <input
        type="search"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        placeholder="Find someone…"
        aria-label="Search people"
        className="w-full rounded-md border border-n-200 bg-n-0 px-3 py-1.5 text-body-sm text-n-900 placeholder:text-n-400 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30 sm:w-64"
      />

      {/* Announced, not just animated: the list below changes under the user
          and a spinner alone tells a screen reader nothing. */}
      <span role="status" aria-live="polite" className="sr-only">
        {isPending ? "Searching" : ""}
      </span>
    </div>
  );
}
