"use client";

import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
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
  const t = useT();
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
        {/* Tinted rather than solid: docs/09 allows ONE primary per screen,
            and a queue of seven decisions was rendering seven of them. It is
            still the affirmative of the row and still the first control. */}
        <Button
          variant="affirmative"
          size="sm"
          disabled={submitting}
          onClick={() => send("approved", "")}
        >
          {submitting ? t("decide.approving") : t("decide.approve")}
        </Button>

        {/* NOT destructive, and the inbox is what proved it. Seven rows with
            two red buttons each is a wall of red, and red that appears
            everywhere stops meaning anything.
            
            The rule survives the correction — asking for changes takes nothing
            away. The work goes back to its author and carries on; it is the
            ordinary outcome of a review, not the end of one. Only Reject
            stops it (ADR 0024). */}
        <Button size="sm" disabled={submitting} onClick={() => setPending("changes_requested")}>
          {t("decide.requestChanges")}
        </Button>

        <Button
          variant="destructive"
          size="sm"
          disabled={submitting}
          onClick={() => setPending("rejected")}
        >
          {t("decide.reject")}
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
        {t(pending === "rejected" ? "decide.whyReject" : "decide.whatChange", { reference })}
      </label>

      <textarea
        id={fieldId}
        required
        autoFocus
        rows={3}
        value={comment}
        onChange={(event) => setComment(event.target.value)}
        placeholder={t("decide.placeholder")}
        // The shared input, not a fourth hand-rolled copy of it: this textarea
        // had a lighter border and a different focus ring from every other
        // control in the product (docs/09 §5).
        className={INPUT}
      />

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
        </p>
      )}

      <div className="flex items-center gap-2">
        {/* The confirmation of the decision just chosen — red, because both
            of the decisions that reach this form stop the work where it is
            (ADR 0024). Approving never comes through here: it needs no note. */}
        <Button
          type="submit"
          variant="danger"
          size="sm"
          disabled={submitting || comment.trim() === ""}
        >
          {pending === "rejected" ? t("decide.reject") : t("decide.sendBack")}
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
          {t("decide.cancel")}
        </Button>
      </div>
    </form>
  );
}
