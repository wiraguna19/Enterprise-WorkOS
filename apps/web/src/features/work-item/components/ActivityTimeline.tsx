import { Avatar } from "@/components/ui/Avatar";

export type ActivityEvent = {
  correlation_id: string;
  occurred_at: string;
  actor: string;
  actor_membership_id: string | null;
  entries: Array<{ verb: string; changes: Record<string, unknown> }>;
};

/**
 * What happened to this item, and who did it (docs/02 §8).
 *
 * The log has been written since Phase 2 and read by nobody: `activity.view`
 * was granted to every role and had no endpoint behind it, so the section
 * heading above the comments said "Activity & comments" over a list of
 * comments. It says "Comments" now, and this is the activity.
 *
 * One EVENT per row, not one row per record. A single decision writes several
 * entries — a transition that also reassigned, a rename that also moved the
 * item — and they carry one correlation id precisely so this does not read as
 * four separate decisions a second apart.
 */
export function ActivityTimeline({
  events,
  timeZone,
}: {
  events: ActivityEvent[];
  timeZone: string;
}) {
  if (events.length === 0) {
    return (
      <p className="py-2 text-body-sm text-n-500">
        Nothing has happened to this item yet.
      </p>
    );
  }

  return (
    <ol className="space-y-0">
      {events.map((event, index) => (
        <li key={event.correlation_id} className="flex gap-3 py-2">
          <div className="flex flex-col items-center">
            <Avatar
              id={event.actor_membership_id ?? event.correlation_id}
              name={event.actor}
              size="sm"
            />
            {index < events.length - 1 && <span className="mt-1 w-px flex-1 bg-n-100" />}
          </div>

          <div className="min-w-0 flex-1 pb-1">
            <p className="text-body text-n-700">
              <span className="font-medium text-n-900">{event.actor}</span>{" "}
              {event.entries.map((entry, i) => (
                <span key={`${entry.verb}-${i}`}>
                  {i > 0 && <span className="text-n-500">, and </span>}
                  {describe(entry.verb, entry.changes)}
                </span>
              ))}
            </p>

            <p className="text-caption text-n-500">
              <time dateTime={event.occurred_at}>
                {new Date(event.occurred_at).toLocaleString("en-GB", {
                  timeZone,
                  dateStyle: "medium",
                  timeStyle: "short",
                })}
              </time>
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}

/**
 * A verb, in words.
 *
 * Deliberately falls back to the verb itself rather than to "did something":
 * an unmapped verb should look unfinished, not look fine. The log is written by
 * seven modules and a rule engine, and a friendly catch-all here would quietly
 * swallow every verb this list has not caught up with.
 */
function describe(verb: string, changes: Record<string, unknown>): string {
  // `label` is the state as the workflow names it; `state` is the category.
  // Rows written before the label was recorded have only the category, so this
  // falls back to it rather than losing the destination — an old row reads
  // "moved it to in_review", which is coarse but true.
  const named = changes.label as { from?: string; to?: string } | undefined;
  const move = changes.state as { from?: string; to?: string } | undefined;
  const destination = named?.to ?? move?.to;

  switch (verb) {
    case "created":
      return "created this";
    case "status_changed":
      return destination ? `moved it to ${destination}` : "changed its status";
    case "submitted_for_review":
      return "submitted it for review";
    case "review_withdrawn":
      return "withdrew it from review";
    case "assigned":
      return "assigned it";
    case "reassigned":
      return "reassigned it";
    case "unassigned":
      return "removed an assignee";
    case "moved":
      return "moved it to another project";
    case "updated":
      return `changed ${listFields(changes)}`;
    case "time_logged":
      return "logged time";
    case "time_removed":
      return "removed a time entry";
    default:
      return verb.replace(/_/g, " ");
  }
}

function listFields(changes: Record<string, unknown>): string {
  const fields = Object.keys(changes).map((field) => field.replace(/_/g, " "));

  if (fields.length === 0) return "something";
  if (fields.length === 1) return fields[0];

  return `${fields.slice(0, -1).join(", ")} and ${fields[fields.length - 1]}`;
}
