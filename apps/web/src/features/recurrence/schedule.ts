/**
 * The handful of schedules this product offers, and the RRULE each one means.
 *
 * **Deliberately not an RRULE editor.** RFC 5545 can express "the last weekday
 * before the 15th of every third month", the API accepts it, and the library
 * behind it runs it — but a field that takes the whole grammar is a field
 * nobody can fill in without reading a spec, and a picker that covers four
 * shapes and says so is more honest than a text box that implies mastery.
 * Phase 7 owns the visual builders; this is the part people ask for daily.
 *
 * The composed rule is SHOWN on the form. What gets stored is what the person
 * can read back, which is the difference between a picker and a black box.
 */
export type Frequency = "daily" | "weekly" | "monthly";

export const WEEKDAYS = [
  { value: "MO", label: "Monday" },
  { value: "TU", label: "Tuesday" },
  { value: "WE", label: "Wednesday" },
  { value: "TH", label: "Thursday" },
  { value: "FR", label: "Friday" },
  { value: "SA", label: "Saturday" },
  { value: "SU", label: "Sunday" },
] as const;

export type Schedule = {
  frequency: Frequency;
  /** Every N days/weeks/months. */
  interval: number;
  /** Weekly only. */
  weekday: string;
  /** Monthly only, 1–28. */
  monthDay: number;
};

/**
 * 1 to 28 for a monthly rule, not 1 to 31.
 *
 * "The 31st of every month" silently skips February, April, June, September
 * and November — the rule is valid, produces nothing in those months, and the
 * person who set it up finds out when the work does not appear. Refusing the
 * days that cannot happen everywhere is the honest bound; a rule for month-end
 * is `BYMONTHDAY=-1` and belongs to the builder that can explain it.
 */
export const MAX_MONTH_DAY = 28;

export function toRrule(schedule: Schedule): string {
  const every = schedule.interval > 1 ? `;INTERVAL=${schedule.interval}` : "";

  switch (schedule.frequency) {
    case "daily":
      return `FREQ=DAILY${every}`;
    case "weekly":
      return `FREQ=WEEKLY${every};BYDAY=${schedule.weekday}`;
    case "monthly":
      return `FREQ=MONTHLY${every};BYMONTHDAY=${schedule.monthDay}`;
  }
}

/**
 * A rule in words.
 *
 * Only the shapes the picker produces are described; anything else — a rule
 * created through the API, or by a future builder — falls back to the rule
 * itself. **A wrong description is worse than a raw string**: somebody reads
 * "every Monday", believes it, and never checks.
 */
export function describe(rrule: string): string {
  const parts = new Map(
    rrule.split(";").map((part) => {
      const [key, value] = part.split("=");

      return [key, value ?? ""] as const;
    }),
  );

  const interval = Number(parts.get("INTERVAL") ?? "1");

  if ([...parts.keys()].some((key) => !["FREQ", "INTERVAL", "BYDAY", "BYMONTHDAY"].includes(key))) {
    return rrule;
  }

  switch (parts.get("FREQ")) {
    case "DAILY":
      return interval === 1 ? "Every day" : `Every ${interval} days`;

    case "WEEKLY": {
      const day = WEEKDAYS.find((weekday) => weekday.value === parts.get("BYDAY"));

      if (!day) return rrule;

      return interval === 1 ? `Every ${day.label}` : `Every ${interval} weeks on ${day.label}`;
    }

    case "MONTHLY": {
      const day = Number(parts.get("BYMONTHDAY") ?? "");

      if (!Number.isInteger(day) || day < 1) return rrule;

      return interval === 1
        ? `On the ${ordinal(day)} of every month`
        : `On the ${ordinal(day)}, every ${interval} months`;
    }

    default:
      return rrule;
  }
}

function ordinal(day: number): string {
  if (day % 100 >= 11 && day % 100 <= 13) return `${day}th`;

  return `${day}${["th", "st", "nd", "rd"][day % 10] ?? "th"}`;
}
