import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import { formatAge, formatDateTime } from "@/lib/format";
import type { Approval } from "@/features/work-item/types";
import { DecisionForm } from "./DecisionForm";

/**
 * The review queue (docs/08 §7).
 *
 * A reviewer's real question is not "what is pending" but "what can I decide in
 * the next ten minutes", so each row carries the submission note — the thing
 * the submitter wrote to explain what they did. Making the reviewer open every
 * item to find that out is what turns a five-minute queue into an afternoon,
 * and an afternoon into a queue nobody clears.
 *
 * Rows are ordered oldest first. A newest-first review queue quietly starves
 * the submission that has been waiting longest, which is the one most likely to
 * be blocking someone.
 */
export function ReviewQueue({
  approvals,
  timeZone,
  emptyLabel,
}: {
  approvals: Approval[];
  timeZone?: string;
  emptyLabel: string;
}) {
  if (approvals.length === 0) {
    return <p className="py-8 text-body text-n-500">{emptyLabel}</p>;
  }

  return (
    <ul className="divide-y divide-n-100 border-y border-n-100">
      {approvals.map((approval) => (
        <li key={approval.id} className="py-3">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <Link
              href={`/work/${approval.subject?.reference ?? ""}`}
              className="text-body font-medium text-n-900 hover:text-a-700 hover:underline"
            >
              {approval.subject?.title ?? "Untitled"}
            </Link>

            <span className="font-mono text-caption text-n-500">
              {approval.subject?.reference}
            </span>

            {approval.subject && (
              <PriorityIcon priority={approval.subject.priority} withLabel />
            )}

            {/* How long, not when. The reviewer's question is "how long has
                someone been blocked", and a timestamp makes them do the
                subtraction. The exact time stays available on hover. */}
            <span
              className="ml-auto text-caption tabular-nums text-n-500"
              title={formatDateTime(approval.submitted_at, timeZone)}
            >
              waiting {formatAge(approval.submitted_at)}
            </span>
          </div>

          <div className="mt-1 flex items-center gap-1.5 text-caption text-n-500">
            <Avatar
              id={approval.requester.membership_id}
              name={approval.requester.name ?? "?"}
              size="sm"
            />
            <span>{approval.requester.name}</span>

            {/* Quorum is stated, not implied. "1 of 3 approvals" tells the
                reviewer whether their decision resolves this or not. */}
            {approval.policy !== "any_one" && (
              <>
                <span aria-hidden>·</span>
                <span>
                  {approval.decisions.filter((d) => d.decision === "approved").length} of{" "}
                  {approval.required_approvals} approvals
                </span>
              </>
            )}
          </div>

          {approval.submission_note && (
            // Clamped to three lines, not truncated to one: the opening of a
            // submission note is usually the whole summary.
            //
            // Whitespace is collapsed first. Keeping the author's line breaks
            // inside a clamp spends two of the three lines on a blank one and
            // leaves a lone ellipsis on the third — which is what it did on a
            // 390px screen. The full note, breaks intact, is on the item page.
            <p className="mt-1.5 line-clamp-3 max-w-[78ch] text-body-sm text-n-700">
              {approval.submission_note.replace(/\s+/g, " ").trim()}
            </p>
          )}

          {/* A previous round trip is the single most useful piece of context
              on a resubmission, and the easiest to lose. */}
          {approval.decisions.length > 0 && (
            <p className="mt-1.5 border-l-2 border-n-200 pl-2 text-caption text-n-500">
              {approval.decisions.at(-1)?.reviewer} previously{" "}
              {(approval.decisions.at(-1)?.decision ?? "").replace("_", " ")}:{" "}
              {approval.decisions.at(-1)?.comment}
            </p>
          )}

          {/* Decide here, not two clicks away. Rendered only when the server
              said this person may decide — the button is a reflection of the
              API's answer, never a substitute for asking it. */}
          {approval.permissions.decide && (
            <DecisionForm
              approvalId={approval.id}
              reference={approval.subject?.reference ?? "this"}
            />
          )}
        </li>
      ))}
    </ul>
  );
}
