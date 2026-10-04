"use client";

import { useId, useRef } from "react";
import { Button } from "@/components/ui/Button";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { EventChip } from "./EventChip";
import type { CalendarEvent } from "./types";

/**
 * "+2 more", and what it was counting.
 *
 * The count used to be plain text: a busy day showed three events and then the
 * NUMBER of the rest, with no way to see which they were. Work due that day
 * was on the calendar and unreachable from it — the unreachable-feature shape
 * this product keeps finding, at the size of one cell.
 *
 * A dialog rather than a link to the agenda. The question is "what else is on
 * Thursday", asked while looking at the month; answering it by leaving the
 * month loses the thing the person was looking at.
 *
 * The native `<dialog>`, opened with `showModal()`, because it does by
 * construction what the hand-rolled overlays elsewhere in this app do by
 * effort or not at all: the page behind is inert, Esc closes it, and focus
 * goes back to the button that opened it.
 *
 * It lists EVERY event of the day, the three the cell showed included. A list
 * of "the rest" would make somebody piece the day together from two places.
 */
export function DayOverflow({
  dayLabel,
  hidden,
  events,
}: {
  /** The day in words — "Thursday 15 October 2026". */
  dayLabel: string;
  /** How many the cell could not show. */
  hidden: number;
  /** All of the day's events, in the cell's order. */
  events: CalendarEvent[];
}) {
  const t = useT();
  const locale = useLocale();
  const dialog = useRef<HTMLDialogElement>(null);
  const headingId = useId();

  return (
    <>
      <button
        type="button"
        onClick={() => dialog.current?.showModal()}
        className="mt-0.5 rounded-sm px-1 text-micro text-n-500 hover:bg-n-50 hover:text-n-700 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-a-500"
      >
        {t("cal.more", { count: hidden })}
        {/* The visible words start the accessible name (WCAG 2.5.3), and the
            day finishes it: thirty buttons all called "+2 more" are thirty
            buttons a screen reader cannot tell apart. */}
        <span className="sr-only">{t("cal.moreOn", { day: dayLabel })}</span>
      </button>

      <dialog
        ref={dialog}
        aria-labelledby={headingId}
        // A click on the backdrop lands on the dialog element itself; a click
        // inside lands on a child. Only the first closes it.
        onClick={(event) => {
          if (event.target === event.currentTarget) event.currentTarget.close();
        }}
        className="m-auto w-full max-w-md rounded-xl border border-n-300 bg-n-0 p-0 shadow-e2 backdrop:bg-n-900/20"
      >
        <div className="flex items-start justify-between gap-3 border-b border-n-100 px-4 py-3">
          <div>
            <h2 id={headingId} className="text-body font-semibold text-n-900">
              {dayLabel}
            </h2>
            <p className="text-caption text-n-500">
              {t.plural("cal.dates", events.length)}
            </p>
          </div>

          <Button variant="ghost" size="sm" onClick={() => dialog.current?.close()}>
            {t("tpl.close")}
          </Button>
        </div>

        <ul className="max-h-[60vh] space-y-1 overflow-y-auto px-4 py-3">
          {events.map((event) => (
            <li key={event.id}>
              <EventChip event={event} roomy locale={locale} />
            </li>
          ))}
        </ul>
      </dialog>
    </>
  );
}
