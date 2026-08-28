"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import type { CalendarSource } from "./types";

const SOURCES: Array<{ key: CalendarSource; label: string }> = [
  { key: "work", label: "Work" },
  { key: "milestones", label: "Milestones" },
  { key: "recurring", label: "Recurring" },
];

/**
 * Which sources the calendar draws from, in the URL (docs/08 §5).
 *
 * Turning everything off is not allowed: it produces an empty calendar that
 * looks broken rather than filtered, and the way to see nothing is to not open
 * the page. The last remaining source therefore cannot be unchecked.
 */
export function SourceFilter({ active }: { active: CalendarSource[] }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const toggle = (source: CalendarSource) => {
    const next = active.includes(source)
      ? active.filter((s) => s !== source)
      : [...active, source];

    if (next.length === 0) return;

    const params = new URLSearchParams(searchParams);

    // Omitted rather than listed when everything is on: a bare /calendar is the
    // link people share, and it should mean "all of it" for whoever opens it.
    if (next.length === SOURCES.length) {
      params.delete("sources");
    } else {
      params.set("sources", next.join(","));
    }

    router.replace(`${pathname}?${params}`, { scroll: false });
  };

  return (
    <div className="flex flex-wrap items-center gap-3">
      {SOURCES.map((source) => {
        const on = active.includes(source.key);

        return (
          <label key={source.key} className="flex items-center gap-1.5 text-body-sm text-n-700">
            <input
              type="checkbox"
              checked={on}
              disabled={on && active.length === 1}
              onChange={() => toggle(source.key)}
              className="size-3.5 accent-a-500"
            />
            {source.label}
          </label>
        );
      })}
    </div>
  );
}
