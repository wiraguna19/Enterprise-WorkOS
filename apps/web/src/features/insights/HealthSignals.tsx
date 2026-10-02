import Link from "next/link";
import type { Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator, type Translator } from "@/i18n/translate";
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
  locale = "en",
}: {
  health: Health;
  projectKey: string;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);
  const date = (value: string) => formatDate(value, undefined, locale);
  const { signals, thresholds } = health;
  const drill = (signal: string) =>
    `/projects/${projectKey}/overview/items?signal=${signal}`;

  return (
    // No outer border: this list sits inside a Panel now and meets its edges
    // (ADR 0024). A list that draws its own top and bottom rule inside a
    // bordered container puts two lines a pixel apart.
    <ul className="divide-y divide-n-100">
      <Signal
        locale={locale}
        name={t("health.schedule.name")}
        status={signals.schedule.status}
        figure={
          signals.schedule.end_date === null
            ? t("health.schedule.noEnd")
            : t("health.due", { date: date(signals.schedule.end_date) })
        }
        rule={
          signals.schedule.end_date === null
            ? t("health.schedule.ruleNoEnd")
            : t("health.schedule.rule", { days: thresholds.schedule_warning_days })
        }
        drillTo={health.open_count > 0 ? drill("open") : undefined}
        drillLabel={t("health.stillOpen", { count: health.open_count })}
      />

      <Signal
        locale={locale}
        name={t("health.overdue.name")}
        status={signals.overdue_work.status}
        figure={
          signals.overdue_work.status === "unknown"
            ? t("health.noWork")
            : t("health.overdue.figure", { count: signals.overdue_work.count, open: signals.overdue_work.open_count })
        }
        rule={t("health.overdue.rule", { percent: Math.round(thresholds.overdue_off_track_share * 100) })}
        drillTo={signals.overdue_work.count > 0 ? drill("overdue") : undefined}
        drillLabel={t("count.overdue", { count: signals.overdue_work.count })}
      />

      <Signal
        locale={locale}
        name={t("health.blocked.name")}
        status={signals.blocked_work.status}
        figure={
          signals.blocked_work.status === "unknown"
            ? t("health.noWork")
            : signals.blocked_work.count === 0
              ? t("health.blocked.none")
              : signals.blocked_work.longest_days === null
                ? t("health.blocked.count", { count: signals.blocked_work.count })
                : t("health.blocked.longest", {
                    count: signals.blocked_work.count,
                    days: signals.blocked_work.longest_days,
                  })
        }
        rule={t("health.blocked.rule", { days: thresholds.blocked_off_track_days })}
        drillTo={signals.blocked_work.count > 0 ? drill("blocked") : undefined}
        drillLabel={t("health.blocked.count", { count: signals.blocked_work.count })}
      />

      <Signal
        locale={locale}
        name={t("health.milestones.name")}
        status={signals.milestones.status}
        figure={
          signals.milestones.count === 0
            ? t("health.milestones.none")
            : t("health.milestones.figure", { past: signals.milestones.past_due_count, count: signals.milestones.count })
        }
        rule={t("health.milestones.rule")}
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
                    ? date(milestone.due_date)
                    : t("health.noDate")}{" "}
                  · {milestoneStatus(milestone.status, t)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Signal>

      <Signal
        locale={locale}
        name={t("health.activity.name")}
        status={signals.activity.status}
        figure={
          signals.activity.days_since === null
            ? t("health.activity.none")
            : t("health.activity.last", { days: signals.activity.days_since })
        }
        rule={t("health.activity.rule", {
          risk: thresholds.activity_at_risk_days,
          off: thresholds.activity_off_track_days,
        })}
        drillTo={signals.activity.stale_count > 0 ? drill("stale") : undefined}
        drillLabel={t("health.activity.drill", { count: signals.activity.stale_count })}
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
  locale,
}: {
  locale: Locale;
  name: string;
  status: HealthStatus;
  figure: string;
  rule: string;
  drillTo?: string;
  drillLabel?: string;
  children?: React.ReactNode;
}) {
  return (
    // `px-4` matches the panel's own padding: the list bleeds to the border,
    // so each row has to carry the gutter the container gave up.
    <li className="flex gap-4 px-4 py-3">
      <StatusDot status={status} locale={locale} />

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
export function StatusDot({ status, locale = "en" }: { status: HealthStatus; locale?: Locale }) {
  const label = translator(locale)(`health.status.${status}`);

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

/** A milestone's status in the reader's language; one the API adds later shows as it arrives. */
function milestoneStatus(status: string, t: Translator): string {
  return ["open", "at_risk", "completed", "missed"].includes(status)
    ? t(`milestone.status.${status}` as MessageKey)
    : status.replace("_", " ");
}
