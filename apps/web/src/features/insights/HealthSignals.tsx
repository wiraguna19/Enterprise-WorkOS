import Link from "next/link";
import { formatDate } from "@/lib/format";
import { clsx } from "@/lib/clsx";
import type { Health, HealthStatus } from "./types";

/**
 * Why a project is amber (ADR 0008).
 *
 * Five signals, each with its verdict, its count, and the rule that produced
 * it printed underneath. There is no composite score anywhere on this page: the
 * roadmap asked for "explainable, not a black box", and a single number is
 * precisely the thing that cannot answer the question the page exists to
 * answer.
 *
 * The rule text lives here rather than in the API response, which returns the
 * thresholds as numbers. Prose in an API is a localisation trap and a second
 * home for the rule; the numbers below are the ones the verdict was computed
 * from, so the sentence cannot drift from the check.
 */
export function HealthSignals({
  health,
  projectKey,
}: {
  health: Health;
  projectKey: string;
}) {
  const { signals, thresholds } = health;
  const drill = (signal: string) =>
    `/projects/${projectKey}/overview/items?signal=${signal}`;

  return (
    <ul className="divide-y divide-n-100 border-y border-n-100">
      <Signal
        name="Schedule"
        status={signals.schedule.status}
        figure={
          signals.schedule.end_date === null
            ? "No end date"
            : `Due ${formatDate(signals.schedule.end_date)}`
        }
        rule={
          signals.schedule.end_date === null
            ? "A project with no end date has no schedule to be on. Unknown, rather than green."
            : `Late once the end date has passed with work still open; a warning inside ${thresholds.schedule_warning_days} days. A project with nothing open is finished, not late.`
        }
        drillTo={health.open_count > 0 ? drill("open") : undefined}
        drillLabel={`${health.open_count} still open`}
      />

      <Signal
        name="Overdue work"
        status={signals.overdue_work.status}
        figure={
          signals.overdue_work.status === "unknown"
            ? "No work yet"
            : `${signals.overdue_work.count} of ${signals.overdue_work.open_count} open`
        }
        rule={`A warning at one overdue item; off track once overdue work reaches ${Math.round(
          thresholds.overdue_off_track_share * 100,
        )}% of what is open. Finished work is never counted as late.`}
        drillTo={signals.overdue_work.count > 0 ? drill("overdue") : undefined}
        drillLabel={`${signals.overdue_work.count} overdue`}
      />

      <Signal
        name="Blocked work"
        status={signals.blocked_work.status}
        figure={
          signals.blocked_work.status === "unknown"
            ? "No work yet"
            : signals.blocked_work.count === 0
              ? "Nothing blocked"
              : signals.blocked_work.longest_days === null
                ? `${signals.blocked_work.count} blocked`
                : `${signals.blocked_work.count} blocked · longest ${signals.blocked_work.longest_days} d`
        }
        rule={`Off track once something has been blocked for ${thresholds.blocked_off_track_days} days — past that it has stopped being a hand-off and become a stall. Measured from the most recent block, not the first.`}
        drillTo={signals.blocked_work.count > 0 ? drill("blocked") : undefined}
        drillLabel={`${signals.blocked_work.count} blocked`}
      />

      <Signal
        name="Milestones"
        status={signals.milestones.status}
        figure={
          signals.milestones.count === 0
            ? "None set"
            : `${signals.milestones.past_due_count} past due of ${signals.milestones.count}`
        }
        rule="A warning at one past-due milestone; off track at two, or at any milestone marked missed. A milestone that was completed late is not held against the project."
      >
        {health.past_due_milestones.length > 0 && (
          // The records behind this signal, listed here rather than a click
          // away: there are never many, and there is no milestone page to
          // send anyone to yet.
          <ul className="mt-2 space-y-1">
            {health.past_due_milestones.map((milestone) => (
              <li
                key={milestone.id}
                className="flex items-baseline gap-2 text-caption"
              >
                <span className="text-n-900">{milestone.name}</span>
                <span className="text-n-500">
                  {milestone.due_date
                    ? formatDate(milestone.due_date)
                    : "no date"}{" "}
                  · {milestone.status.replace("_", " ")}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Signal>

      <Signal
        name="Activity"
        status={signals.activity.status}
        figure={
          signals.activity.days_since === null
            ? "Nothing has moved yet"
            : `Last movement ${signals.activity.days_since} d ago`
        }
        rule={`A warning after ${thresholds.activity_at_risk_days} days without a single item moving, off track after ${thresholds.activity_off_track_days}. A project with nothing open is allowed to be quiet.`}
        drillTo={signals.activity.stale_count > 0 ? drill("stale") : undefined}
        drillLabel={`${signals.activity.stale_count} sitting still`}
      />
    </ul>
  );
}

function Signal({
  name,
  status,
  figure,
  rule,
  drillTo,
  drillLabel,
  children,
}: {
  name: string;
  status: HealthStatus;
  figure: string;
  rule: string;
  drillTo?: string;
  drillLabel?: string;
  children?: React.ReactNode;
}) {
  return (
    <li className="flex gap-4 py-3">
      <StatusDot status={status} />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <span className="font-medium text-n-900">{name}</span>
          <span className="text-body-sm tabular-nums text-n-700">{figure}</span>
        </div>

        <p className="mt-0.5 max-w-[72ch] text-caption text-n-500">{rule}</p>

        {drillTo && (
          <Link
            href={drillTo}
            className="mt-1 inline-block text-caption text-a-700 hover:underline"
          >
            {drillLabel} →
          </Link>
        )}

        {children}
      </div>
    </li>
  );
}

/**
 * Colour is never the only carrier: the status is written out beside the dot.
 * A red/amber/green page that means nothing in greyscale means nothing to a
 * reader with a colour vision deficiency either (docs/09 §2).
 */
export function StatusDot({ status }: { status: HealthStatus }) {
  const label = {
    on_track: "On track",
    at_risk: "At risk",
    off_track: "Off track",
    unknown: "Unknown",
  }[status];

  return (
    <span className="flex w-24 shrink-0 items-baseline gap-2">
      <span
        aria-hidden
        className={clsx(
          "mt-1.5 h-2 w-2 shrink-0 rounded-full",
          status === "on_track" && "bg-s-success",
          status === "at_risk" && "bg-s-active",
          status === "off_track" && "bg-s-danger",
          status === "unknown" && "bg-n-300",
        )}
      />
      <span className="text-caption text-n-700">{label}</span>
    </span>
  );
}
