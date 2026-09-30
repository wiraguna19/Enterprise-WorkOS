import Link from "next/link";
import { clsx } from "@/lib/clsx";
import type { CalendarEvent } from "./types";

/**
 * One event, as the month grid and the day's full list both draw it.
 *
 * A projected occurrence is drawn as an outline, never filled.
 *
 * "This will appear on Monday" and "this exists and is due Monday" are
 * different facts, and a calendar that draws them identically teaches people to
 * distrust all of it (docs/10, Phase 5). It is also not a link: there is
 * nothing to open, because the work item does not exist yet.
 *
 * `roomy` is the day list's version: the title wraps instead of truncating,
 * and the project is named. A cell has room for neither; a dialog that exists
 * to show what the cell could not would be pointless if it cut titles short
 * the same way.
 */
export function EventChip({ event, roomy = false }: { event: CalendarEvent; roomy?: boolean }) {
  const label = (
    <span className={clsx("block", !roomy && "truncate")}>
      {event.reference && <span className="font-mono text-micro">{event.reference} </span>}
      {event.title}
      {roomy && event.project && (
        <span className="block text-micro text-n-500">{event.project}</span>
      )}
    </span>
  );

  const size = roomy ? "px-2 py-1.5 text-body-sm" : "px-1 text-micro";

  if (event.is_projected) {
    return (
      <span
        title={`${event.title} — recurring, not created yet`}
        className={clsx("block rounded-sm border border-dashed border-n-300 text-n-500", size)}
      >
        {label}
        {roomy && <span className="block text-micro">Recurring — not created yet.</span>}
      </span>
    );
  }

  if (event.type === "milestone") {
    return (
      <span className={clsx("block rounded-sm bg-s-active/10 text-n-700", size)}>{label}</span>
    );
  }

  return (
    <Link
      href={`/work/${event.reference}`}
      className={clsx(
        "block rounded-sm bg-a-50 text-n-700 hover:bg-a-50 hover:text-a-700",
        size,
      )}
    >
      {label}
    </Link>
  );
}
