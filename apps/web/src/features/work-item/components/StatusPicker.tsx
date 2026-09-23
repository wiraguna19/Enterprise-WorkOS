"use client";

import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { clsx } from "@/lib/clsx";
import { transitionTo } from "../actions";
import type { Transition } from "../types";

/**
 * The status control, rendered from the workflow graph (docs/02 §7).
 *
 * Nothing about the set of moves is written here. The list comes from
 * GET /work-items/{ref}/available-transitions, which is the same query the API
 * uses to authorise the write — so this menu cannot offer a move the server
 * will refuse, and a workflow edited in the database changes this menu without
 * a deploy.
 *
 * Two things it deliberately does NOT do:
 *
 *   - It does not hide blocked moves. `available: false` renders disabled with
 *     the server's reason attached, because "why can't I approve this?" is
 *     answered better in place than by a control that silently disappeared.
 *   - It does not decide for itself when a comment is required. That flag rides
 *     on the transition, and the API enforces it again on write.
 */
export function StatusPicker({
  reference,
  current,
  transitions,
}: {
  reference: string;
  current: { label: string; category: string } | null;
  transitions: Transition[];
}) {
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState<Transition | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [moving, startMoving] = useTransition();
  const menuId = useId();

  // The move itself. Until this existed the menu closed and nothing happened —
  // the endpoint had been there since Phase 3 and nothing in the interface
  // called it.
  const move = (transition: Transition, comment?: string): void => {
    setError(null);

    startMoving(async () => {
      const result = await transitionTo(reference, transition.to_state.id, comment);

      // The server's own refusal, shown where the click was. The rules that
      // produce it — the edge, the permission, the required comment — live in
      // the API and are not repeated here.
      setError(result.error);
    });
  };

  if (transitions.length === 0) return null;

  return (
    <div className="relative">
      <Button
        type="button"
        aria-expanded={open}
        aria-controls={menuId}
        aria-haspopup="menu"
        disabled={moving}
        onClick={() => setOpen((v) => !v)}
      >
        {moving ? "Moving…" : (current?.label ?? "Status")}
        <span aria-hidden className="text-n-500">
          ▾
        </span>
      </Button>

      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label={`Move ${reference}`}
          className="absolute right-0 z-20 mt-1 w-72 rounded-sm border border-n-200 bg-n-0 py-1 shadow-sm"
        >
          {transitions.map((transition) => (
            <button
              key={transition.id}
              type="button"
              role="menuitem"
              // A real disabled button, not a greyed-out div: it is
              // contrast-exempt and announces its state to a screen reader,
              // which a colour change alone does not.
              disabled={!transition.available}
              onClick={() => {
                setOpen(false);

                if (transition.requires_comment) {
                  setPending(transition);

                  return;
                }

                move(transition);
              }}
              className={clsx(
                "flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left",
                transition.available
                  ? "text-n-900 hover:bg-n-50"
                  : "cursor-not-allowed text-n-500",
              )}
            >
              <span className="flex w-full items-center justify-between gap-2 text-body">
                {transition.label}
                {transition.requires_comment && transition.available && (
                  <span className="text-micro text-n-500">needs a reason</span>
                )}
              </span>

              {transition.blocked_reason && (
                <span className="text-caption text-n-500">{transition.blocked_reason}</span>
              )}
            </button>
          ))}
        </div>
      )}

      {pending && (
        <CommentPrompt
          transition={pending}
          reference={reference}
          onCancel={() => setPending(null)}
          onSubmit={(comment) => {
            setPending(null);
            move(pending, comment);
          }}
        />
      )}

      {error && (
        <p role="alert" className="absolute right-0 z-20 mt-1 w-72 rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-caption text-s-danger">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * Asking for the reason at the moment of the decision.
 *
 * Bouncing work back without saying why sends it round the loop again, so the
 * comment is part of the action rather than a follow-up the reviewer is trusted
 * to remember.
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
  const fieldId = useId();

  return (
    // A real overlay, not a panel hanging off the trigger.
    //
    // It used to be `absolute right-0` under the status button, which put it
    // behind the mobile tab bar — `fixed bottom-0 z-20`, the same layer — at
    // 375px. The reason box was visible, the confirm button underneath it was
    // not clickable, and the only edge into review is the one that asks for a
    // reason: **on a phone, work could not be submitted at all.** Nothing said
    // so; the button was simply inert under the nav.
    //
    // So it now matches the board's prompt, which asks the same question and
    // got this right: fixed, z-30, above the chrome, bottom-sheet on small
    // screens and centred above them. One question, one shape.
    <div className="fixed inset-0 z-30 flex items-end justify-center bg-n-900/20 p-4 sm:items-center">
      <form
        // Announced as a dialog, like the board's. This one predates that one
        // and never got the treatment: it appeared, it took focus from nobody,
        // and a screen reader was told only that a text field had turned up
        // somewhere on the page.
        //
        // The name also gives its confirm button somewhere to be unambiguous.
        // The button repeats the transition's label — "Submit for review" —
        // which is also on the sticky bar behind it, so without a named
        // container there are two buttons with one name.
        role="dialog"
        aria-modal="true"
        aria-label={`Move ${reference} to ${transition.to_state.label}`}
        className="w-full max-w-md space-y-2 rounded-sm border border-n-200 bg-n-0 p-4 shadow-sm"
        onSubmit={(event) => {
          event.preventDefault();
          onSubmit(comment);
        }}
      >
        <label htmlFor={fieldId} className="block text-caption font-medium text-n-700">
          Why are you moving this to {transition.to_state.label}?
        </label>

        <textarea
          id={fieldId}
          autoFocus
          required
          rows={3}
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          className={INPUT}
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
