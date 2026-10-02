import Link from "next/link";
import { notFound } from "next/navigation";
import { Avatar } from "@/components/ui/Avatar";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { DecisionForm } from "@/features/inbox/DecisionForm";
import { WithdrawButton } from "@/features/inbox/WithdrawButton";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import type { Approval } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatAge, formatAgo, formatDateTime } from "@/lib/format";

/**
 * One approval, in full.
 *
 * `GET /approvals/{id}` had existed since Phase 4 with nothing calling it, and
 * the reachability list said what it was owed in one line: "an approval has no
 * detail screen". That absence was not free. The route was gated on
 * `approval.decide` while its policy named the requester a participant, so the
 * submitter could withdraw a submission the API would not let her read — a
 * contradiction nobody hit, because nobody could get there.
 *
 * What this page has that the queue row cannot:
 *
 *   - **The note as it was written.** The row collapses whitespace and clamps
 *     to three lines, on purpose; a queue is for triage.
 *   - **Every decision, not the last one.** docs/02 §4.3: a changed decision
 *     must show BOTH records, or the trail pretends the first never happened.
 *     A row that shows `decisions.at(-1)` is structurally unable to.
 *   - **Who else was asked.** "1 of 3 approvals" says the shape of the quorum
 *     and not one name in it.
 *   - **Approvals that are over.** The queue is `status=pending`. Once decided,
 *     an approval left the product entirely — the API kept an audit trail with
 *     no reader, which is this codebase's oldest recurring defect.
 */
export default async function ApprovalPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  let approval: Approval;

  try {
    ({ data: approval } = await api<Approval>(`/approvals/${id}`));
  } catch (error) {
    // 403 is folded into 404 here rather than shown. An approval you may not
    // read is one you should not be able to confirm the existence of, and the
    // API answering 403 does not oblige the product to repeat it (docs/05 §3).
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  const decided = approval.decisions.filter((d) => d.decision === "approved").length;
  // Translated (ADR 0060), with the screen it is reached from, the Inbox.
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      {/* The trail every other nested screen has, instead of a hand-drawn back
          link: this approval really is inside the review queue (ADR 0026). */}
      <Breadcrumb
        locale={locale}
        items={[
          { label: t("approval.queue"), href: "/inbox?tab=reviews" },
          { label: approval.subject?.reference ?? t("approval.fallback") },
        ]}
      />

      <PageHeader
        title={approval.subject?.title ?? t("review.untitled")}
        description={
          approval.status === "pending"
            ? t("approval.waiting", { age: formatAge(approval.submitted_at, new Date(), locale) })
            : t("approval.resolved", {
                status: t(`approval.status.${approval.status}`),
                age: formatAgo(approval.resolved_at, locale),
              })
        }
      />

      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-n-500">
        {approval.subject && (
          <>
            <Link
              href={`/work/${approval.subject.reference}`}
              className="font-mono text-n-700 hover:text-a-700 hover:underline"
            >
              {approval.subject.reference}
            </Link>
            <PriorityIcon priority={approval.subject.priority} withLabel locale={locale} />
          </>
        )}

        <span title={formatDateTime(approval.submitted_at, me.user.timezone, locale)}>
          {t("approval.submittedAge", { age: formatAgo(approval.submitted_at, locale) })}
        </span>

        {/* Stated whenever it is not the trivial case, and stated as a count
            rather than implied by the roster below: "any one of" and "two of
            four" look identical in a list of names. */}
        {approval.policy !== "any_one" && (
          <span>{t("review.approvalsOf", { done: decided, required: approval.required_approvals })}</span>
        )}
      </div>

      <PageBody>
      <Panel
        id="submitted"
        title={t("approval.submitted.title")}
        description={t("approval.submitted.description")}
      >
      <div className="space-y-2">

        <div className="flex items-center gap-1.5 text-caption text-n-500">
          {approval.requested_by && (
            <>
              <Avatar
                id={approval.requested_by.membership_id}
                name={approval.requested_by.name ?? "?"}
                size="sm"
              />
              <span>{approval.requested_by.name ?? t("review.unknown")}</span>
            </>
          )}
        </div>

        {/* `whitespace-pre-wrap`, and no clamp. The paragraph breaks the
            submitter typed are part of what they wrote; the queue drops them to
            fit three lines, and this page is the reason that is acceptable
            there. */}
        {approval.submission_note ? (
          <p className="max-w-[78ch] whitespace-pre-wrap text-body text-n-700">
            {approval.submission_note}
          </p>
        ) : (
          <p className="text-body text-n-500">{t("approval.noNote")}</p>
        )}
      </div>
      </Panel>

      <Panel
        id="reviewers"
        title={t("approval.reviewers.title")}
        description={
          approval.policy === "any_one"
            ? t("approval.reviewers.anyOne")
            : t("approval.reviewers.mustApprove", { count: approval.required_approvals })
        }
      >

        {/* Absent, not empty, when the response did not load the relation —
            which is a different sentence from "nobody is asked", and saying the
            wrong one about an approval is how work sits pending forever while
            the page insists no reviewer exists. */}
        {approval.reviewers === undefined ? (
          <p className="text-body text-n-500">{t("approval.reviewers.unavailable")}</p>
        ) : approval.reviewers.length === 0 ? (
          <p className="text-body text-n-500">{t("approval.reviewers.nobody")}</p>
        ) : (
          <ul className="flex flex-wrap gap-x-4 gap-y-2">
            {approval.reviewers.map((person) => (
              <li
                key={person.membership_id}
                className="flex items-center gap-1.5 text-body text-n-700"
              >
                <Avatar
                  id={person.membership_id}
                  name={person.name ?? "?"}
                  size="sm"
                />
                <span>{person.name ?? t("review.unknown")}</span>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel
        id="decisions"
        title={t("approval.decisions.title")}
        description={t("approval.decisions.description")}
      >

        {approval.decisions.length === 0 ? (
          <p className="text-body text-n-500">{t("approval.decisions.none")}</p>
        ) : (
          // Oldest first, and ALL of them. A reviewer who approved and then
          // asked for changes leaves two rows; showing the later one alone
          // reads as though the first was never made (docs/02 §4.3).
          <ol className="space-y-3 border-l-2 border-n-200 pl-3">
            {approval.decisions.map((decision) => (
              <li key={decision.id}>
                <p className="text-body-sm text-n-900">
                  <span className="font-medium">{decision.reviewer ?? t("approval.decisions.someone")}</span>{" "}
                  {t(`decision.verb.${decision.decision}`)}
                  <span
                    className="ml-2 text-caption text-n-500"
                    title={formatDateTime(decision.decided_at, me.user.timezone, locale)}
                  >
                    {formatAgo(decision.decided_at, locale)}
                  </span>
                </p>

                {decision.comment && (
                  <p className="mt-0.5 max-w-[78ch] whitespace-pre-wrap text-body-sm text-n-700">
                    {decision.comment}
                  </p>
                )}
              </li>
            ))}
          </ol>
        )}
      </Panel>

      {/* Both controls are the server's answer, echoed. Neither is inferred
          from the status: a resolved approval sends neither permission, so
          nothing renders, without this page needing its own opinion about
          which statuses are still actionable (docs/06 §2). */}
      {approval.permissions.decide && (
        <DecisionForm
          approvalId={approval.id}
          reference={approval.subject?.reference ?? t("decide.this")}
        />
      )}

      {approval.permissions.withdraw && <WithdrawButton approvalId={approval.id} />}
      </PageBody>
    </div>
  );
}
