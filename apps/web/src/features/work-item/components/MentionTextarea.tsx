"use client";

import { useId, useRef, useState, useTransition } from "react";
import { clsx } from "@/lib/clsx";
import { mentionable, type Mentionable } from "../actions";

/**
 * A textarea that finishes the name for you (docs/08 §7).
 *
 * Before this, mentioning somebody meant knowing how the product spells them.
 * `@rina` resolved to nobody, because the server matches the DISPLAY NAME and
 * "Rina Wijaya" is what is stored — and it failed the way this codebase keeps
 * finding things: silently, with the comment posted and nobody told.
 *
 * So the list offers people, and inserting one writes the exact string the
 * server resolves. That is the whole point of the control: **the picker
 * cannot know the matching rule, so it must not try to reproduce it** — it
 * inserts a stored name verbatim and lets the server do what it already does.
 * Nothing here is trusted server-side; mentions are still extracted from the
 * text (a client-supplied list would let anyone notify the company).
 *
 * The trigger is an `@` that begins a word, with the letters typed since. It
 * deliberately does NOT mirror the server's parser: this only decides what to
 * SEARCH for, and searching for slightly the wrong thing costs a query, while
 * a second copy of the matching rule would drift and cost a mention.
 */
export function MentionTextarea({
  value,
  onChange,
  rows = 2,
  placeholder,
  ariaLabel,
  disabled,
  className,
}: {
  value: string;
  onChange: (next: string) => void;
  rows?: number;
  placeholder?: string;
  ariaLabel?: string;
  disabled?: boolean;
  className?: string;
}) {
  const field = useRef<HTMLTextAreaElement>(null);
  const [people, setPeople] = useState<Mentionable[]>([]);
  const [active, setActive] = useState(0);
  const [token, setToken] = useState<{ start: number; query: string } | null>(null);
  const [, startTransition] = useTransition();
  const listId = useId();

  // Every response is checked against the token that was current when it was
  // asked for. Server actions resolve out of order, and without this the
  // results for "ri" can land after the results for "rina" and quietly replace
  // them — the same guard the command palette needed.
  const latest = useRef(0);

  /**
   * The `@word` immediately before the caret, if the caret is inside one — and
   * the search for it.
   *
   * The search runs HERE rather than in an effect watching the token. Typing is
   * the event; the list is a reaction to that event and not to a rendered
   * value, and an effect would mean a render with a stale list before the
   * render that clears it. `react-hooks/set-state-in-effect` was naming a real
   * defect, as it usually does.
   */
  const readToken = (element: HTMLTextAreaElement) => {
    const caret = element.selectionStart ?? 0;
    const before = element.value.slice(0, caret);
    const match = /(?:^|\s)@([\p{L}\p{N}'\- ]{0,40})$/u.exec(before);

    if (match === null) {
      // Both, together: a token with no list and a list with no token are each
      // a state this component should never render.
      latest.current += 1;
      setToken(null);
      setPeople([]);

      return;
    }

    const query = match[1];

    // The start of the `@`, not of the match: the match may include the space
    // that preceded it, and replacing that too would join the mention to the
    // previous word.
    setToken({ start: caret - query.length - 1, query });

    const attempt = ++latest.current;

    startTransition(async () => {
      const found = await mentionable(query);

      if (attempt === latest.current) {
        setPeople(found);
        setActive(0);
      }
    });
  };

  const insert = (person: Mentionable) => {
    const element = field.current;

    if (element === null || token === null) return;

    const caret = element.selectionStart ?? 0;
    const next = `${value.slice(0, token.start)}@${person.name} ${value.slice(caret)}`;

    onChange(next);
    latest.current += 1;
    setToken(null);
    setPeople([]);

    // The caret goes after the name and its trailing space, so typing carries
    // on where the sentence was. Restored in a frame, because React has to
    // render the new value before the position means anything.
    const position = token.start + person.name.length + 2;

    requestAnimationFrame(() => {
      element.focus();
      element.setSelectionRange(position, position);
    });
  };

  const open = token !== null && people.length > 0;

  return (
    <div className="relative flex-1">
      <textarea
        ref={field}
        value={value}
        rows={rows}
        placeholder={placeholder}
        aria-label={ariaLabel}
        disabled={disabled}
        // A combobox that owns a listbox, so the name being offered is
        // announced rather than only drawn (docs/09 §5).
        role="combobox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-activedescendant={open ? `${listId}-${active}` : undefined}
        aria-autocomplete="list"
        onChange={(event) => {
          onChange(event.target.value);
          readToken(event.target);
        }}
        onClick={(event) => readToken(event.currentTarget)}
        onKeyUp={(event) => {
          // Arrow keys move the caret, which changes what is being typed —
          // but they also drive the list, so the list's own keys are excluded.
          if (!open || (event.key !== "ArrowDown" && event.key !== "ArrowUp")) {
            readToken(event.currentTarget);
          }
        }}
        onKeyDown={(event) => {
          if (!open) return;

          if (event.key === "ArrowDown") {
            event.preventDefault();
            setActive((current) => (current + 1) % people.length);
          } else if (event.key === "ArrowUp") {
            event.preventDefault();
            setActive((current) => (current - 1 + people.length) % people.length);
          } else if (event.key === "Enter" || event.key === "Tab") {
            // Enter picks the name rather than sending the comment: the list is
            // open, and a send that swallowed the selection would post "@rin".
            event.preventDefault();
            insert(people[active]);
          } else if (event.key === "Escape") {
            event.preventDefault();
            latest.current += 1;
            setToken(null);
            setPeople([]);
          }
        }}
        onBlur={() => {
          // Closed on the next frame, not immediately: a click on an option is
          // a blur first, and closing here would remove the option before the
          // click could land on it.
          requestAnimationFrame(() => {
            latest.current += 1;
            setToken(null);
            setPeople([]);
          });
        }}
        className={clsx(
          "w-full resize-y rounded-sm border border-n-200 bg-n-0 px-2.5 py-1.5 text-body text-n-900 outline-none transition-colors placeholder:text-n-300 focus:border-a-500",
          className,
        )}
      />

      {open && (
        <ul
          id={listId}
          role="listbox"
          aria-label="People you can mention"
          className="absolute bottom-full z-10 mb-1 max-h-56 w-72 overflow-y-auto rounded-sm border border-n-200 bg-n-0 py-1 shadow-lg"
        >
          {people.map((person, index) => (
            <li
              key={person.id}
              id={`${listId}-${index}`}
              role="option"
              aria-selected={index === active}
              // onMouseDown, not onClick: mousedown fires before the blur, so
              // the option is still there when the press lands.
              onMouseDown={(event) => {
                event.preventDefault();
                insert(person);
              }}
              onMouseEnter={() => setActive(index)}
              className={clsx(
                "cursor-pointer px-2.5 py-1.5 text-body-sm",
                index === active ? "bg-n-50 text-n-900" : "text-n-700",
              )}
            >
              {person.name}
              {person.jobTitle !== null && (
                <span className="ml-1.5 text-caption text-n-500">{person.jobTitle}</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
