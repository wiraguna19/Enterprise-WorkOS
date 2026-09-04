"use client";

import { useRef, useState, useTransition, type RefObject } from "react";
import { Button } from "@/components/ui/Button";
import { clsx } from "@/lib/clsx";
import type { BoardColumn as Column, Transition, WorkItem } from "@/features/work-item/types";
import { dropOnColumn, legalMoves, reorderInColumn } from "./actions";
import { BoardCard } from "./BoardCard";

/**
 * The board, and the one interaction in this product that is not a link or a
 * form (ADR 0012).
 *
 * Two ways to move a card, converging on ONE function:
 *
 *   - Pointer: native HTML5 drag events, because they are what the browser and
 *     assistive technology already understand.
 *   - Keyboard: Space picks up, ←/→ choose a column, Space drops, Escape
 *     cancels. Not a fallback bolted onto the drag — the same call.
 *
 * Nothing is optimistic. The card moves when the server says it moved (ADR 0012
 * §1): an optimistic board shows the card in its new column and then puts it
 * back when a guard refuses, and that revert is the one path nobody exercises.
 * While the request is in flight the card says so.
 */
type Picked = {
  item: WorkItem;
  fromStateId: string;
  transitions: Transition[] | null;
  /** Where the keyboard is currently pointing; ignored during a pointer drag. */
  targetIndex: number;
};

export function Board({
  columns,
  projectKey,
  timeZone,
}: {
  columns: Column[];
  projectKey: string;
  timeZone: string;
}) {
  const [picked, setPicked] = useState<Picked | null>(null);
  const [moving, setMoving] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [prompt, setPrompt] = useState<{ item: WorkItem; transition: Transition } | null>(null);
  const [announcement, setAnnouncement] = useState("");
  const [, startMoving] = useTransition();

  // Focus goes back to the card after a keyboard move, so a second move does
  // not begin with hunting for where the card went.
  const boardRef = useRef<HTMLDivElement>(null);

  /**
   * What is being dragged, readable in the same tick it was picked up.
   *
   * `picked` is state, and state arrives on the next render. React treats drag
   * events as continuous rather than discrete, so a `dragstart` and the first
   * `dragover` can land before that render: the `dragover` handler would read
   * `null`, decline to call `preventDefault()` — which is how a target says
   * "not here" — and the drop would never fire, silently, because our own code
   * never ran.
   *
   * Written as a guard against a window that is possible rather than one that
   * was observed: the failure it was first written for turned out to be an
   * assertion of mine, not this. It stays because the reasoning holds on its
   * own, and because a silent no-op is the worst thing a gesture can do.
   */
  const draggingRef = useRef<{ item: WorkItem; fromStateId: string } | null>(null);

  const columnIndexOf = (stateId: string): number =>
    columns.findIndex((column) => column.state.id === stateId);

  /** Pick a card up — from a pointer drag or from the keyboard, identically. */
  const pickUp = async (item: WorkItem, fromStateId: string): Promise<void> => {
    setError(null);
    draggingRef.current = { item, fromStateId };
    setPicked({ item, fromStateId, transitions: null, targetIndex: columnIndexOf(fromStateId) });
    announce(`${item.reference} picked up. Use left and right to choose a column, space to drop.`);

    // Asked once per pick-up, for this card only. Until it answers, every
    // column reads as "not yet known" rather than as droppable — the board must
    // never suggest a move the graph does not have.
    const transitions = await legalMoves(item.reference);

    setPicked((current) =>
      current && current.item.id === item.id ? { ...current, transitions } : current,
    );
  };

  const cancel = (): void => {
    if (picked) announce(`${picked.item.reference} left in place.`);
    draggingRef.current = null;
    setPicked(null);
  };

  /**
   * Put the picked card in a column. The whole point of this component.
   */
  const drop = (toStateId: string): void => {
    // The same race as the drop targets': a drop can arrive before `picked` has
    // rendered. The ref carries the card; `transitions` may legitimately still
    // be unknown, and an unknown graph means the server decides — attempting
    // and being refused with its reason beats dropping the gesture silently.
    const dragging = draggingRef.current;
    const current = picked ?? (dragging
      ? { item: dragging.item, fromStateId: dragging.fromStateId, transitions: null, targetIndex: -1 }
      : null);

    if (!current) return;

    draggingRef.current = null;

    const { item, fromStateId, transitions } = current;

    // Back where it started: a reorder with no neighbours, which is a no-op the
    // API would accept. Treated as a cancel rather than a write.
    if (toStateId === fromStateId) {
      cancel();

      return;
    }

    const transition = transitions?.find((t) => t.to_state.id === toStateId);

    if (transitions !== null && !transition) {
      // The graph has no edge here. Refused where the drop happened, naming the
      // destination — "nothing happened" is the failure this replaces.
      refuse(item, columns[columnIndexOf(toStateId)]?.state.label ?? "that column",
        "The workflow has no move from here to there.");

      return;
    }

    if (transition && !transition.available) {
      refuse(item, transition.to_state.label,
        transition.blocked_reason ?? "This move is not available right now.");

      return;
    }

    if (transition?.requires_comment) {
      // A gesture cannot supply a reason, and an edge that demands one demands
      // it however the move was started (ADR 0012 §3).
      setPicked(null);
      setPrompt({ item, transition });

      return;
    }

    commit(item, toStateId);
  };

  const commit = (item: WorkItem, toStateId: string, comment?: string): void => {
    setPicked(null);
    setMoving(item.id);
    setError(null);
    announce(`Moving ${item.reference}…`);

    startMoving(async () => {
      const result = await dropOnColumn(item.reference, projectKey, toStateId, comment);

      setMoving(null);
      setError(result.error);
      announce(
        result.error === null
          ? `${item.reference} moved.`
          : `${item.reference} was not moved. ${result.error}`,
      );

      if (result.error === null) {
        // The list re-renders from the server; put focus back on the card.
        requestAnimationFrame(() => {
          boardRef.current
            ?.querySelector<HTMLElement>(`[data-card="${item.reference}"]`)
            ?.focus();
        });
      }
    });
  };

  const refuse = (item: WorkItem, where: string, why: string): void => {
    draggingRef.current = null;
    setPicked(null);
    setError(`${item.reference} cannot move to ${where}. ${why}`);
    announce(`${item.reference} cannot move to ${where}. ${why}`);
  };

  const announce = (message: string): void => setAnnouncement(message);

  const move = (delta: number): void => {
    setPicked((current) => {
      if (!current) return current;

      const next = Math.min(columns.length - 1, Math.max(0, current.targetIndex + delta));

      if (next !== current.targetIndex) {
        announce(`${columns[next].state.label}, ${columns[next].items.length} items.`);
      }

      return { ...current, targetIndex: next };
    });
  };

  return (
    <>
      {error && (
        <p
          role="alert"
          className="rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-caption text-s-danger"
        >
          {error}
        </p>
      )}

      {/* The board's running commentary for a screen reader. Assertive, because
          it narrates an interaction the user is in the middle of. */}
      <p aria-live="assertive" className="sr-only">
        {announcement}
      </p>

      <div
        ref={boardRef}
        className="-mx-4 overflow-x-auto px-4 pb-4 md:-mx-8 md:px-8"
        onKeyDown={(event) => {
          if (!picked) return;

          if (event.key === "Escape") {
            event.preventDefault();
            cancel();
          } else if (event.key === "ArrowRight") {
            event.preventDefault();
            move(1);
          } else if (event.key === "ArrowLeft") {
            event.preventDefault();
            move(-1);
          } else if (event.key === " " || event.key === "Enter") {
            event.preventDefault();
            drop(columns[picked.targetIndex].state.id);
          }
        }}
      >
        <div className="flex gap-4">
          {columns.map((column, index) => (
            <BoardColumnDropZone
              key={column.state.id}
              column={column}
              timeZone={timeZone}
              picked={picked}
              draggingRef={draggingRef}
              isKeyboardTarget={picked?.targetIndex === index}
              movingId={moving}
              onPickUp={pickUp}
              onCancel={cancel}
              onDrop={drop}
              onReorder={(item, before, after) => {
                setMoving(item.id);
                startMoving(async () => {
                  const result = await reorderInColumn(item.reference, projectKey, before, after);

                  setMoving(null);
                  setError(result.error);
                });
              }}
            />
          ))}
        </div>
      </div>

      {prompt && (
        <CommentPrompt
          transition={prompt.transition}
          reference={prompt.item.reference}
          onCancel={() => setPrompt(null)}
          onSubmit={(comment) => {
            const { item, transition } = prompt;

            setPrompt(null);
            commit(item, transition.to_state.id, comment);
          }}
        />
      )}
    </>
  );
}

/**
 * One column, and the surface a card can be dropped onto.
 *
 * The column knows whether the picked card may land here, and says so before
 * the drop rather than after it — but only once the graph has answered. While
 * `transitions` is null nothing is highlighted, because "we do not know yet" is
 * not the same as "no".
 */
function BoardColumnDropZone({
  column,
  timeZone,
  picked,
  draggingRef,
  isKeyboardTarget,
  movingId,
  onPickUp,
  onCancel,
  onDrop,
  onReorder,
}: {
  column: Column;
  timeZone: string;
  picked: Picked | null;
  draggingRef: RefObject<{ item: WorkItem; fromStateId: string } | null>;
  isKeyboardTarget: boolean;
  movingId: string | null;
  onPickUp: (item: WorkItem, fromStateId: string) => void;
  onCancel: () => void;
  onDrop: (toStateId: string) => void;
  onReorder: (item: WorkItem, beforeId: string | null, afterId: string | null) => void;
}) {
  const [over, setOver] = useState(false);

  const isSource = picked?.fromStateId === column.state.id;
  const legal = picked?.transitions?.some((t) => t.to_state.id === column.state.id && t.available);
  const known = picked?.transitions != null;

  return (
    <section
      aria-labelledby={`column-${column.state.key}`}
      data-column={column.state.key}
      aria-dropeffect={picked && !isSource ? (legal ? "move" : "none") : undefined}
      className={clsx(
        "flex w-72 shrink-0 flex-col rounded-sm border border-transparent p-1 transition-colors duration-[120ms]",
        (over || isKeyboardTarget) && !isSource && "border-a-500 bg-a-500/5",
        picked && known && !legal && !isSource && "opacity-50",
      )}
      onDragOver={(event) => {
        // Read from the ref, not from `picked`: this handler can run in the
        // same task as the `dragstart` that began the drag, before any state
        // has rendered.
        const dragging = draggingRef.current;

        if (!dragging || dragging.fromStateId === column.state.id) return;

        // Only a droppable column takes the event: leaving preventDefault off
        // is how the browser is told this is not a valid target, and the cursor
        // says so on its own.
        if (known && !legal) return;

        event.preventDefault();
        setOver(true);
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(event) => {
        event.preventDefault();
        setOver(false);
        onDrop(column.state.id);
      }}
    >
      <header className="flex items-center gap-2 px-1 pb-2">
        <span
          aria-hidden
          className={clsx("size-1.5 rounded-full", CATEGORY_DOT[column.state.category])}
        />
        <h2 id={`column-${column.state.key}`} className="text-body-sm font-semibold text-n-900">
          {column.state.label}
        </h2>
        {/* The column's real size, not the number of cards on screen. */}
        <span className="text-caption text-n-500 tabular-nums">{column.total}</span>
      </header>

      <ol className="flex flex-1 flex-col gap-1.5">
        {column.items.length === 0 ? (
          <li className="rounded-sm border border-dashed border-n-200 px-3 py-6 text-center text-caption text-n-500">
            Nothing here
          </li>
        ) : (
          column.items.map((item, index) => (
            <BoardCard
              key={item.id}
              item={item}
              column={column}
              timeZone={timeZone}
              picked={picked?.item.id === item.id}
              moving={movingId === item.id}
              onPickUp={() => onPickUp(item, column.state.id)}
              onCancel={onCancel}
              onReorderTo={(direction) => {
                const target = index + direction;

                if (target < 0 || target >= column.items.length) return;

                // The neighbours the card will sit between, in the list's own
                // order. Sending ids rather than an index is what keeps the
                // write to one row (docs/03 §3).
                const [before, after] = direction < 0
                  ? [column.items[target - 1] ?? null, column.items[target]]
                  : [column.items[target], column.items[target + 1] ?? null];

                onReorder(item, before?.id ?? null, after?.id ?? null);
              }}
            />
          ))
        )}
      </ol>

      {column.hidden_count > 0 && (
        <p className="px-1 pt-2 text-caption text-n-500">
          {/* Said out loud rather than left as a silently short list. A board is
              read as "everything in this state", and a column that quietly
              stopped at fifty would be read that way too.
              There is deliberately no link here yet: no screen lists one
              column's items, and pointing at a page that does not answer the
              question is worse than admitting the limit. ADR 0012 carries it as
              the follow-up. */}
          {column.hidden_count} more in this column, not shown here
        </p>
      )}
    </section>
  );
}

const CATEGORY_DOT: Record<string, string> = {
  backlog: "bg-s-neutral",
  todo: "bg-s-info",
  in_progress: "bg-s-active",
  in_review: "bg-s-review",
  blocked: "bg-s-danger",
  done: "bg-s-success",
  cancelled: "bg-n-300",
};

/**
 * The reason, asked at the moment of the move.
 *
 * The same prompt the status menu shows, for the same rule: an edge marked
 * `requires_comment` cannot be satisfied by a gesture, and letting a drag skip
 * it would make the board the way around a rule the rest of the product keeps.
 */
function CommentPrompt({
  transition,
  reference,
  onCancel,
  onSubmit,
}: {
  transition: Transition;
  reference: string;
  onCancel: () => void;
  onSubmit: (comment: string) => void;
}) {
  const [comment, setComment] = useState("");

  return (
    <div className="fixed inset-0 z-30 flex items-end justify-center bg-n-900/20 p-4 sm:items-center">
      <form
        role="dialog"
        aria-modal="true"
        aria-label={`Move ${reference} to ${transition.to_state.label}`}
        className="w-full max-w-md space-y-2 rounded-sm border border-n-200 bg-n-0 p-4 shadow-sm"
        onSubmit={(event) => {
          event.preventDefault();
          onSubmit(comment);
        }}
      >
        <label htmlFor="board-move-comment" className="block text-caption font-medium text-n-700">
          Why are you moving {reference} to {transition.to_state.label}?
        </label>

        <textarea
          id="board-move-comment"
          autoFocus
          required
          rows={3}
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          className="w-full rounded-sm border border-n-200 px-2 py-1.5 text-body text-n-900 focus:border-a-500 focus:outline-2 focus:outline-offset-1 focus:outline-a-500"
          placeholder="The person picking this up next reads this first."
        />

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" size="sm" disabled={comment.trim() === ""}>
            {transition.label}
          </Button>
        </div>
      </form>
    </div>
  );
}
