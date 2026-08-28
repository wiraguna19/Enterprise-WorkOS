import { Avatar } from "@/components/ui/Avatar";

type Entry = {
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
 * The narrative that justifies assignment being an entity (docs/02 §6).
 *
 * Rendered as a timeline of FACTS rather than a current-state summary: who had
 * this, who handed it over, when, and why. A pivot table could show the first
 * line of this and nothing else.
 */
export function AssignmentHistory({
  entries,
  timeZone,
}: {
  entries: Entry[];
  timeZone: string;
}) {
  return (
    <ol className="space-y-0">
      {entries.map((entry, index) => (
        <li key={entry.id} className="flex gap-3 py-2">
          <div className="flex flex-col items-center">
            <Avatar id={entry.id} name={entry.person ?? "?"} size="sm" />
            {index < entries.length - 1 && <span className="mt-1 w-px flex-1 bg-n-100" />}
          </div>

          <div className="min-w-0 flex-1 pb-1">
            <p className="text-body text-n-700">
              <span className="font-medium text-n-900">{entry.person ?? "Someone"}</span>
              {entry.role !== "assignee" && (
                <span className="text-n-500"> as {entry.role}</span>
              )}
              {entry.active ? (
                <span className="ml-1.5 rounded-xs bg-a-50 px-1.5 py-0.5 text-micro font-medium text-a-700">
                  current
                </span>
              ) : (
                <span className="text-n-500"> — handed over</span>
              )}
            </p>

            {/* The reason is the part people ask about weeks later, so it is
                shown, not tucked behind a tooltip. */}
            {entry.reason && (
              <p className="mt-0.5 text-caption text-n-500">{entry.reason}</p>
            )}

            <p className="mt-0.5 text-caption text-n-500 tabular-nums">
              {format(entry.assigned_at, timeZone)}
              {entry.accepted_at
                ? ` · accepted ${format(entry.accepted_at, timeZone)}`
                : entry.active && entry.role === "assignee"
                  ? " · not yet accepted"
                  : ""}
              {entry.unassigned_at && ` · until ${format(entry.unassigned_at, timeZone)}`}
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}

function format(value: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    timeZone,
  }).format(new Date(value));
}
