import { notFound } from "next/navigation";
import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { StatusChip } from "@/components/ui/StatusChip";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import { DueDate } from "@/features/work-item/components/DueDate";
import { AssignmentHistory } from "@/features/work-item/components/AssignmentHistory";
import { CommentThread } from "@/features/work-item/components/CommentThread";
import { PrimaryAction } from "@/features/work-item/components/PrimaryAction";
import { WorkItemChannel } from "@/features/realtime/WorkItemChannel";
import { TimePanel } from "@/features/time/TimePanel";
import type { TimeEntry } from "@/features/time/types";
import type { Transition, WorkItem } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

type Comment = {
  id: string;
  author: { membership_id: string; name: string | null; avatar_url: string | null };
  body_html: string;
  created_at: string;
  edited: boolean;
};

type HistoryEntry = {
  id: string;
  role: string;
  person: string | null;
  assigned_by: string | null;
  assigned_at: string;
  accepted_at: string | null;
  unassigned_at: string | null;
  reason: string | null;
  active: boolean;
};

/**
 * The work item page (docs/08 §4).
 *
 * A full page, not a modal over a random background: this URL gets pasted into
 * chat, and a deep link that opens a dialog on top of whatever the user was
 * doing is disorienting. Within a board the same component renders as a side
 * panel — one component, two routes.
 *
 * There is exactly ONE primary action, and it is always the next legal step in
 * the workflow. The user is never asked to work out which status to pick from a
 * dropdown in order to make progress.
 */
export default async function WorkItemPage({
  params,
}: {
  params: Promise<{ reference: string }>;
}) {
  const [me, { reference }] = await Promise.all([requireUser(), params]);

  let item: WorkItem;

  try {
    ({ data: item } = await api<WorkItem>(`/work-items/${reference}`));
  } catch (error) {
    // 404 covers both "does not exist" and "not visible to you" — deliberately
    // indistinguishable, because a 403 would confirm it exists (docs/05 §3).
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  const [comments, history, moves, time] = await Promise.all([
    api<Comment[]>(`/work-items/${reference}/comments`).then((r) => r.data).catch(() => []),
    api<HistoryEntry[]>(`/work-items/${reference}/assignments`).then((r) => r.data).catch(() => []),
    // The legal moves, from the workflow graph. Fetched here rather than in the
    // client so the page renders complete on first paint — a status control
    // that pops in a second later is the kind of thing that gets clicked twice.
    api<{ current: { id: string; category: string }; transitions: Transition[] }>(
      `/work-items/${reference}/available-transitions`,
    ).then((r) => r.data.transitions).catch(() => [] as Transition[]),
    // The rollup comes back WITH the rows it was computed from, so a total that
    // looks wrong can be checked here rather than reported as a mystery
    // (docs/03 §4).
    api<TimeEntry[]>(`/work-items/${reference}/time-entries`)
      .then((r) => ({
        entries: r.data,
        total: Number(r.meta?.total_hours ?? 0),
        cached: Number(r.meta?.cached_total ?? 0),
      }))
      .catch(() => ({ entries: [] as TimeEntry[], total: 0, cached: 0 })),
  ]);

  const assignee = item.assignees?.find((a) => a.role === "assignee");
  const reviewer = item.assignees?.find((a) => a.role === "reviewer");

  return (
    <article className="mx-auto max-w-4xl space-y-6">
      <header className="space-y-3 border-b border-n-100 pb-4">
        <div className="flex items-center gap-2 text-caption text-n-500">
          <span className="font-mono">{item.reference}</span>
          {item.project && (
            <>
              <span aria-hidden>·</span>
              <Link
                href={`/projects/${item.project.key}/board`}
                className="hover:text-a-700 hover:underline"
              >
                {item.project.name}
              </Link>
            </>
          )}
          <span aria-hidden>·</span>
          <PriorityIcon priority={item.priority} withLabel />
        </div>

        <h1 className="text-display font-semibold text-n-900">{item.title}</h1>

        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-body-sm">
          {item.state && (
            <StatusChip category={item.state.category} label={item.state.label} />
          )}

          <Field label="Assignee">
            {assignee ? (
              <span className="inline-flex items-center gap-1.5">
                <Avatar id={assignee.membership_id} name={assignee.name ?? "?"} size="sm" />
                {assignee.name}
                {!assignee.accepted && (
                  <span className="text-caption text-s-active">· not accepted</span>
                )}
              </span>
            ) : (
              <span className="text-s-active">Unassigned</span>
            )}
          </Field>

          <Field label="Reviewer">
            {reviewer ? reviewer.name : <span className="text-n-500">—</span>}
          </Field>

          <Field label="Due">
            <DueDate value={item.due_at} overdue={item.is_overdue} timeZone={me.user.timezone} />
          </Field>

          <Field label="Estimate">
            {item.estimate_hours ? (
              `${item.estimate_hours}h`
            ) : (
              <span className="text-s-active">not estimated</span>
            )}
          </Field>
        </div>
      </header>

      {item.description && (
        <section aria-labelledby="description-heading">
          <SectionLabel id="description-heading">Description</SectionLabel>
          <p className="max-w-[72ch] whitespace-pre-wrap text-body text-n-700">
            {item.description}
          </p>
        </section>
      )}

      {history.length > 0 && (
        <section aria-labelledby="history-heading">
          <SectionLabel id="history-heading">Assignment history</SectionLabel>
          <AssignmentHistory entries={history} timeZone={me.user.timezone} />
        </section>
      )}

      <section aria-labelledby="time-heading">
        <SectionLabel id="time-heading">Time</SectionLabel>
        <TimePanel
          reference={item.reference}
          entries={time.entries}
          total={time.total}
          cachedTotal={time.cached}
          canLog={item.permissions.log_time ?? false}
        />
      </section>

      <section aria-labelledby="comments-heading">
        <SectionLabel id="comments-heading">
          Activity &amp; comments
          {comments.length > 0 && (
            <span className="ml-1 font-normal text-n-500">({comments.length})</span>
          )}
        </SectionLabel>
        <CommentThread
          comments={comments}
          timeZone={me.user.timezone}
          canComment={item.permissions.comment ?? false}
        />
      </section>

      {/* One primary action, always visible, always the next legal step. */}
      <PrimaryAction item={item} transitions={moves} />

      <WorkItemChannel organizationId={me.organization.id} workItemId={item.id} />
    </article>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <span className="inline-flex items-baseline gap-1.5">
      <span className="text-caption text-n-500">{label}</span>
      <span className="text-n-900">{children}</span>
    </span>
  );
}

function SectionLabel({ id, children }: { id: string; children: React.ReactNode }) {
  return (
    <h2
      id={id}
      className="mb-2 text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
    >
      {children}
    </h2>
  );
}
