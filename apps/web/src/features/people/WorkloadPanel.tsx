import Link from "next/link";
import { WorkloadBar } from "@/components/ui/WorkloadBar";
import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { Workload } from "./types";

/**
 * One person's week (docs/02 §11).
 *
 * The bar is never shown alone. Every caveat the number carries is printed
 * beside it, because this is operational capacity signal that someone will make
 * a staffing decision from — and a decision made on a number whose assumptions
 * are invisible is a decision made on the wrong number.
 *
 * Three caveats, all from the API rather than invented here: work counted at
 * the organization's default estimate, committed work with no dates that lands
 * in no week at all, and a capacity that has not been reduced for leave because
 * nothing in the system records leave yet.
 */
export function WorkloadPanel({
  workload,
  locale = "en",
}: {
  workload: Workload;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);

  return (
    <div className="space-y-1.5">
      <WorkloadBar
        committedHours={workload.committed_hours}
        capacityHours={workload.capacity_hours}
        itemCount={workload.item_count}
        unestimatedCount={workload.unestimated_count}
        locale={locale}
      />

      {/* Phase 6's first house rule: a number must be able to show its work.
          The endpoint that explains this figure shipped with the figure and had
          no caller for a phase, so the one number a staffing decision gets made
          from was the one number nobody could check.

          A link, not a button: it GOES somewhere, and a link with no href does
          not render, which is a failure mode a button cannot have. */}
      <Link
        href={`/people/${workload.membership_id}/workload?week=${workload.week_start}`}
        className="inline-block text-caption text-a-700 hover:underline"
      >
        {t.plural("workload.behind", workload.item_count)}
      </Link>

      <p className="text-caption text-n-500">
        {t("workload.week", { week: workload.week_start, hours: workload.default_estimate_hours })}
        {workload.undated_count > 0 && (
          <>
            {" "}
            <span className="text-s-active">{t.plural("workload.undated", workload.undated_count)}</span>{" "}
            {t.plural("workload.undatedTail", workload.undated_count)}
          </>
        )}{" "}
        {workload.time_off_hours === null && t("workload.noLeave")}
      </p>
    </div>
  );
}
