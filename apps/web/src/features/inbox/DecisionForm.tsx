"use client";

import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { decide } from "./actions";

type Decision = "approved" | "changes_requested" | "rejected";

/**
 * Deciding, from the queue (docs/08 §7).
 *
 * The reason this lives on the row rather than behind a click into the item:
 * a reviewer with six submissions and ten minutes will clear the queue or
 * abandon it, and making each decision cost a page load, a read, and a
 * back-button is what turns "I'll do reviews after standup" into a queue nobody
 * clears. The submission note is already on the row; the decision belongs next
 * to it.
 *
 * Approve is one click. The two that send work back are not — they open a
 * comment field first, because bouncing work without saying why sends it round
 * the loop again. That asymmetry is deliberate, and the API enforces it
 * independently: `comment` is `required_unless:decision,approved`, and the
 * database has a CHECK saying the same thing.
 */
export function DecisionForm({
  approvalId,
  reference,
}: {
  approvalId: string;
  reference: string;
}) {
  const [pending, setPending] = useState<Decision | null>(null);
  const [comment, setComment] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, startTransition] = useTransition();
  const fieldId = useId();

  const send = (decision: Decision, text: string) =>
    startTransition(async () => {
      const result = await decide(approvalId, decision, text);

      // The API's refusal is shown verbatim rather than replaced with a generic
      // "something went wrong": it already says which rule was broken.
      setError(result.error);

      if (result.error === null) {
        setPending(null);
        setComment("");
      }
    });

  if (pending === null) {
    return (
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Button
          variant="primary"
          size="sm"
          disabled={submitting}
          onClick={() => send("approved", "")}
        >
          {submitting ? "Approving…" : "Approve"}
        </Button>

        <Button size="sm" disabled={submitting} onClick={() => setPending("changes_requested")}>
          Request changes
        </Button>

        <Button
          variant="ghost"
          size="sm"
          disabled={submitting}
          onClick={() => setPending("rejected")}
        >
          Reject
        </Button>

        {error && (
          <p role="alert" className="text-caption text-s-danger">
            {error}
          </p>
        )}
      </div>
    );
  }

  return (
    <form
      className="mt-2 max-w-[70ch] space-y-2"
      onSubmit={(event) => {
        event.preventDefault();
        send(pending, comment);
      }}
    >
      <label htmlFor={fieldId} className="block text-caption font-medium text-n-700">
        {pending === "rejected"
          ? `Why are you rejecting ${reference}?`
          : `What needs to change in ${reference}?`}
      </label>

      <textarea
        id={fieldId}
        required
        autoFocus
        rows={3}
        value={comment}
        onChange={(event) => setComment(event.target.value)}
        placeholder="Be specific enough that they can act on it without asking you a follow-up question."
        className="w-full rounded-sm border border-n-200 px-2 py-1.5 text-body text-n-900 focus:border-a-500 focus:outline-2 focus:outline-offset-1 focus:outline-a-500"
      />

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
        </p>
      )}

      <div className="flex items-center gap-2">
        <Button
          type="submit"
          variant="primary"
          size="sm"
          disabled={submitting || comment.trim() === ""}
        >
          {pending === "rejected" ? "Reject" : "Send back"}
        </Button>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => {
            setPending(null);
            setComment("");
          }}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
