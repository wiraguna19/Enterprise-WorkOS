"use client";

import { useEffect, useState, useTransition } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";

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
  const t = useT();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const [value, setValue] = useState(initialQuery);
  const [isPending, startTransition] = useTransition();

  // Navigate only when the field asks for something the URL does not already
  // say. This was a "skip the first run" ref, and React runs effects twice on
  // mount in development: the second run got past the ref and replaced the URL
  // 250ms after every visit — harmless to look at, and enough to interrupt a
  // navigation that started in that window (the accessibility spec's next
  // page.goto, on 2026-10-04). Comparing against the URL is true however many
  // times the effect runs, and it still never turns an arrival into a history
  // entry: on arrival the field and the URL agree.
  useEffect(() => {
    const trimmed = value.trim();
    // The API rejects a one-character query (min:2); below that the field is
    // treated as empty rather than sent and refused.
    const wanted = trimmed.length >= 2 ? trimmed : "";

    if (wanted === (searchParams.get("q") ?? "")) return;

    const timer = setTimeout(() => {
      const params = new URLSearchParams(searchParams);

      if (wanted !== "") {
        params.set("q", wanted);
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
        placeholder={t("search.placeholder")}
        aria-label={t("search.label")}
        className={`${INPUT} sm:w-64`}
      />

      {/* Announced, not just animated: the list below changes under the user
          and a spinner alone tells a screen reader nothing. */}
      <span role="status" aria-live="polite" className="sr-only">
        {isPending ? t("search.searching") : ""}
      </span>
    </div>
  );
}
