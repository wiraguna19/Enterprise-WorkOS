import { Avatar } from "@/components/ui/Avatar";
import { INTL_TAG, type Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator, type Translator } from "@/i18n/translate";

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
  emptyMessage,
  locale = "en",
}: {
  events: ActivityEvent[];
  timeZone: string;
  /**
   * The sentence for an empty timeline.
   *
   * A parameter rather than a second component: this one is now read by a
   * project as well as a work item, and the only thing that differs is the
   * noun. Copying the component to change one word is how two timelines end up
   * rendering the same log differently.
   */
  emptyMessage?: string;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);

  if (events.length === 0) {
    return <p className="py-2 text-body-sm text-n-500">{emptyMessage ?? t("act.empty")}</p>;
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
                  {i > 0 && <span className="text-n-500">{t("act.and")}</span>}
                  {describe(entry.verb, entry.changes, t)}
                </span>
              ))}
            </p>

            <p className="text-caption text-n-500">
              <time dateTime={event.occurred_at}>
                {new Date(event.occurred_at).toLocaleString(INTL_TAG[locale], {
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
function describe(verb: string, changes: Record<string, unknown>, t: Translator): string {
  // `label` is the state as the workflow names it; `state` is the category.
  // Rows written before the label was recorded have only the category, so this
  // falls back to it rather than losing the destination — an old row reads
  // "moved it to in_review", which is coarse but true.
  const named = changes.label as { from?: string; to?: string } | undefined;
  const move = changes.state as { from?: string; to?: string } | undefined;
  const destination = named?.to ?? move?.to;

  switch (verb) {
    case "created":
      return t("act.created");
    case "status_changed":
      return destination ? t("act.movedTo", { state: destination }) : t("act.statusChanged");
    case "submitted_for_review":
      return t("act.submitted");
    case "review_withdrawn":
      return t("act.withdrawn");
    case "assigned":
      return t("act.assigned");
    case "reassigned":
      return t("act.reassigned");
    case "unassigned":
      return t("act.unassigned");
    case "moved":
      return t("act.moved");
    case "updated":
      return t("act.changed", { fields: listFields(changes, t) });
    case "time_logged":
      return t("act.timeLogged");
    case "time_removed":
      return t("act.timeRemoved");

    // Projects (ADR 0040, ADR 0041). These were written for two commits with
    // nothing able to read them, so this list had never seen them — an
    // unmapped verb renders as itself, which is why the gap looked like
    // nothing rather than like a bug.
    case "archived":
      return t("act.archived");
    case "restored":
      return t("act.restored");
    case "member_added":
      return t("act.memberAdded", { role: roleIn(changes, t) });
    case "member_removed":
      return t("act.memberRemoved");
    case "member_role_changed":
      return t("act.roleChanged", { role: roleIn(changes, t) });

    // Milestones (ADR 0056). The name travels in `milestone`, so the line
    // reads without opening anything.
    case "milestone_added":
      return t("act.milestoneAdded", { name: milestoneIn(changes, "to") });
    case "milestone_updated": {
      const fields = Object.fromEntries(Object.entries(changes).filter(([field]) => field !== "milestone"));

      return t("act.milestoneUpdated", { fields: listFields(fields, t), name: milestoneIn(changes, "to") });
    }
    case "milestone_removed":
      return t("act.milestoneRemoved", { name: milestoneIn(changes, "from") });

    default:
      return verb.replace(/_/g, " ");
  }
}

/** The milestone's name, quoted, from whichever side of the change holds it. */
function milestoneIn(changes: Record<string, unknown>, side: "from" | "to"): string {
  const milestone = changes.milestone as { from?: string | null; to?: string | null } | undefined;
  const name = milestone?.[side];

  return name ? `“${name}”` : "";
}

/** " as manager", or nothing when the row does not say. */
function roleIn(changes: Record<string, unknown>, t: Translator): string {
  const role = changes.role as { from?: string; to?: string } | undefined;

  return role?.to === undefined ? "" : t("act.asRole", { role: role.to });
}

/**
 * The fields the interface names in the reader's language (ADR 0060). A field
 * the API adds later is shown by its own name until it is given one here.
 */
const FIELDS = new Set([
  "title", "description", "priority", "due_at", "start_at", "estimate_hours",
  "name", "visibility", "target_date", "type", "status", "milestone_id",
]);

function fieldName(field: string, t: Translator): string {
  return FIELDS.has(field) ? t(`field.${field}` as MessageKey) : field.replace(/_/g, " ");
}

function listFields(changes: Record<string, unknown>, t: Translator): string {
  const fields = Object.keys(changes).map((field) => fieldName(field, t));

  if (fields.length === 0) return t("act.something");
  if (fields.length === 1) return fields[0];

  return t("act.listAnd", { list: fields.slice(0, -1).join(", "), last: fields[fields.length - 1] });
}
