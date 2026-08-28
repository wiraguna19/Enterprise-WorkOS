"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, useTransition } from "react";
import { clsx } from "@/lib/clsx";
import { search, type SearchHit } from "./actions";

/**
 * ⌘K — the primary navigation for experienced users and the safety net for
 * everyone else (docs/08 §5).
 *
 * Three decisions worth keeping:
 *
 * 1. Results are grouped by type but ranked across types. The API decides what
 *    is most relevant; grouping is presentation. Sorting the groups themselves
 *    would let a person outrank the work item someone actually meant.
 *
 * 2. The previous results stay on screen while the next query is in flight.
 *    Blanking the list on every keystroke makes the palette flicker and makes
 *    a fast typist feel like they broke it.
 *
 * 3. Every response is checked against the query that is on screen NOW. Server
 *    actions resolve out of order, and without this the results for "eng" can
 *    land after the results for "engine" and quietly replace them.
 */

const TYPE_LABEL: Record<SearchHit["type"], string> = {
  work_item: "Work",
  project: "Projects",
  person: "People",
};

const ORDER: Array<SearchHit["type"]> = ["work_item", "project", "person"];

const MATCH_NOTE: Record<string, string> = {
  comment: "in a comment",
  description: "in the description",
};

export function CommandPalette() {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [hits, setHits] = useState<SearchHit[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [active, setActive] = useState(0);
  const [pending, startTransition] = useTransition();

  const close = useCallback(() => {
    setOpen(false);

    // Reset here rather than in an effect watching `open`: the reset IS part of
    // closing, and doing it as a reaction costs an extra render pass for state
    // nobody can see. A palette that reopens showing the last search looks like
    // it failed to clear, and the first keystroke would append to a query the
    // user has forgotten about.
    setQuery("");
    setHits([]);
    setError(null);
    setActive(0);
  }, []);

  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  /** The query the newest in-flight request was issued for. */
  const latest = useRef("");

  // ⌘K / Ctrl+K anywhere, Esc to leave. Bound on the document rather than on
  // the field: the whole point is that it works without reaching for it.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key.toLowerCase() === "k" && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOpen((wasOpen) => !wasOpen);
      }

      if (event.key === "Escape") {
        close();
      }
    }

    // Also opened by anything that dispatches this event — the phone's bottom
    // navigation, which is nowhere near this component in the tree. An event is
    // the small seam here: the alternative is lifting the palette's open state
    // into the shell so two distant children can share it, which spreads one
    // component's internals across two files for one button.
    function onOpenRequest() {
      setOpen(true);
    }

    document.addEventListener("keydown", onKey);
    window.addEventListener("palette:open", onOpenRequest);

    return () => {
      document.removeEventListener("keydown", onKey);
      window.removeEventListener("palette:open", onOpenRequest);
    };
  }, [close]);

  // Moving focus is what an effect is actually for: synchronising React state
  // with the DOM, an external system.
  useEffect(() => {
    if (open) {
      inputRef.current?.focus();
    }
  }, [open]);

  // Debounced: 150ms is under the threshold where typing feels laggy and far
  // above the rate that would burn the API's 30/min search budget.
  useEffect(() => {
    const terms = query.trim();

    // Nothing to clear: `visible` below is derived from the query, so a query
    // that got too short simply stops showing results.
    if (terms.length < 2) {
      return;
    }

    const timer = setTimeout(() => {
      latest.current = terms;

      startTransition(async () => {
        const outcome = await search(terms);

        // Out-of-order guard — see the note at the top of this file.
        if (latest.current !== terms) {
          return;
        }

        setHits(outcome.results);
        setError(outcome.error);
        setActive(0);
      });
    }, 150);

    return () => clearTimeout(timer);
  }, [query]);

  const go = useCallback(
    (hit: SearchHit) => {
      close();

      const href =
        hit.type === "work_item"
          ? `/work/${hit.reference}`
          : hit.type === "project"
            ? `/projects/${hit.reference}`
            // The person's own page, not the directory scrolled to their row:
            // picking a name out of search means "show me this person", and a
            // highlighted row in a list is a worse answer to that than a page.
            : `/people/${hit.id}`;

      router.push(href);
    },
    [router, close],
  );

  const terms = query.trim();
  // Derived, not stored: results belong to the query that is on screen.
  const visible = terms.length >= 2 ? hits : [];

  function onFieldKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (visible.length === 0) {
      return;
    }

    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActive((index) => (index + 1) % visible.length);
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((index) => (index - 1 + visible.length) % visible.length);
    }

    if (event.key === "Enter") {
      event.preventDefault();
      const hit = visible[active];

      if (hit) {
        go(hit);
      }
    }
  }

  // Keep the highlighted row in view when arrowing past the fold.
  useEffect(() => {
    listRef.current
      ?.querySelector<HTMLElement>(`[data-index="${active}"]`)
      ?.scrollIntoView({ block: "nearest" });
  }, [active]);

  if (!open) {
    return <PaletteTriggers onOpen={() => setOpen(true)} />;
  }

  const grouped = ORDER.map((type) => ({
    type,
    hits: visible.filter((hit) => hit.type === type),
  })).filter((group) => group.hits.length > 0);

  return (
    <>
      <PaletteTriggers onOpen={() => setOpen(true)} />

      <div
        className="fixed inset-0 z-50 bg-n-900/20 p-4 pt-[10vh]"
        onMouseDown={close}
        role="presentation"
      >
        <div
          className="mx-auto w-full max-w-xl overflow-hidden rounded-md border border-n-200 bg-n-0 shadow-lg"
          onMouseDown={(event) => event.stopPropagation()}
          role="dialog"
          aria-modal="true"
          aria-label="Search"
        >
          <div className="flex items-center gap-2 border-b border-n-100 px-3">
            <span aria-hidden className="text-n-500">
              ⌕
            </span>
            <input
              ref={inputRef}
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={onFieldKeyDown}
              placeholder="Search work, projects, people…"
              className="h-11 flex-1 bg-transparent text-body outline-none placeholder:text-n-500"
              aria-label="Search"
              aria-controls="palette-results"
              autoComplete="off"
              spellCheck={false}
            />
            {pending && (
              <span className="text-micro text-n-500" aria-live="polite">
                searching…
              </span>
            )}
          </div>

          <ul id="palette-results" ref={listRef} className="max-h-80 overflow-y-auto py-1">
            {/* An error belongs to a query, exactly like results do: clearing
                the field must clear it too, or the palette opens accusing the
                API of being down when nothing has been asked of it yet. */}
            {error && terms.length >= 2 && (
              <li className="px-3 py-6 text-center text-body-sm text-s-danger">{error}</li>
            )}

            {!error && terms.length >= 2 && visible.length === 0 && !pending && (
              <li className="px-3 py-6 text-center text-body-sm text-n-500">
                Nothing matches “{terms}”.
              </li>
            )}

            {terms.length < 2 && (
              <li className="px-3 py-6 text-center text-body-sm text-n-500">
                Search by title, reference, or something said in a comment.
              </li>
            )}

            {grouped.map((group) => (
              <li key={group.type}>
                <div className="px-3 pb-1 pt-2 text-micro font-medium uppercase tracking-wide text-n-500">
                  {TYPE_LABEL[group.type]}
                </div>

                <ul>
                  {group.hits.map((hit) => {
                    const index = visible.indexOf(hit);

                    return (
                      <li key={`${hit.type}:${hit.id}`}>
                        <button
                          type="button"
                          data-index={index}
                          onMouseEnter={() => setActive(index)}
                          onClick={() => go(hit)}
                          className={clsx(
                            "flex w-full items-baseline gap-2 px-3 py-1.5 text-left",
                            index === active ? "bg-n-50" : "hover:bg-n-50",
                          )}
                        >
                          {hit.reference && (
                            <span className="shrink-0 font-mono text-caption text-n-500">
                              {hit.reference}
                            </span>
                          )}

                          <span className="min-w-0 flex-1 truncate text-body-sm text-n-900">
                            {hit.title}
                          </span>

                          {/* Why this row is here. "Matched in a comment" is
                              the difference between a confusing result and an
                              obvious one. */}
                          {MATCH_NOTE[hit.matched_on] && (
                            <span className="shrink-0 text-micro text-n-500">
                              {MATCH_NOTE[hit.matched_on]}
                            </span>
                          )}

                          {hit.subtitle && !MATCH_NOTE[hit.matched_on] && (
                            <span className="shrink-0 truncate text-caption text-n-500">
                              {hit.subtitle}
                            </span>
                          )}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </li>
            ))}
          </ul>

          <div className="flex items-center gap-3 border-t border-n-100 px-3 py-1.5 text-micro text-n-500">
            <span>↑↓ to move</span>
            <span>↵ to open</span>
            <span>esc to close</span>
          </div>
        </div>
      </div>
    </>
  );
}

/**
 * The entry point from the shell.
 *
 * Nothing renders below `sm`: on a phone the palette is opened from the bottom
 * navigation, which is where the thumb already is. A second trigger in the
 * header would be the same action twice, at the end of the screen furthest from
 * the hand (docs/08 §6).
 */
function PaletteTriggers({ onOpen }: { onOpen: () => void }) {
  return (
    <>
      <button
        type="button"
        onClick={onOpen}
        className="hidden h-7 flex-1 items-center gap-2 rounded-sm border border-n-200 px-2.5 text-left text-body-sm text-n-500 hover:bg-n-50 sm:flex md:max-w-md"
      >
        <span aria-hidden>⌕</span>
        <span className="truncate">Search work, projects, people…</span>
        <kbd className="ml-auto hidden shrink-0 rounded-xs border border-n-200 px-1 font-sans text-micro text-n-500 md:inline">
          ⌘K
        </kbd>
      </button>
    </>
  );
}
