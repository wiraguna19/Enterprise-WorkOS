import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import type { Locale } from "@/i18n/config";
import { priorityName } from "@/i18n/labels";
import { translator } from "@/i18n/translate";

/**
 * A work item template, as `WorkItemTemplateController::present()` sends it
 * (ADR 0047).
 *
 * Hand-written, like every contract in this app, and every field is read by
 * the editor or the picker — a type is only checked against reality by a line
 * that uses it.
 */
export type WorkItemTemplate = {
  id: string;
  name: string;
  /** What it is for, in a sentence. Not the new item's description. */
  purpose: string | null;
  fields: TemplateFields;
  /** The project it belongs to, or null for an organization-wide one (ADR 0058). */
  project: { id: string; key: string; name: string } | null;
  updated_at: string;
};

/**
 * What a template may fill in — `WorkItemTemplates::FIELDS`, and nothing else.
 *
 * No people, no project, no absolute dates: the API refuses each of those BY
 * NAME, and ADR 0047 says why. `due_in_days` is relative to the day the form
 * opens, which is the only kind of deadline a template can honestly hold.
 */
export type TemplateFields = {
  title?: string;
  description?: string;
  type?: string;
  priority?: string;
  estimate_hours?: number | string;
  due_in_days?: number;
  custom_fields?: Record<string, string>;
};

/** `GET /work-items/vocabulary` — served, never copied here. */
export type WorkVocabulary = {
  types: string[];
  priorities: string[];
};

/**
 * A template laid over a blank form, and an account of what did not fit.
 *
 * A custom field the template names may have been retired or deleted since, or
 * the option it chose removed. Those are left OUT of the prefill — a value the
 * API will refuse would make the form unsubmittable for a reason the person
 * cannot see — and listed by name, so the picker says what it did not do. A
 * picker that hides what it composed is a black box with a friendly face.
 */
export type TemplatePrefill = {
  title: string;
  description: string;
  type: string;
  priority: string;
  estimate: string;
  /** `YYYY-MM-DD`, or "" — computed from `due_in_days` and today. */
  dueAt: string;
  custom: Record<string, string>;
  /** Labels (or keys, when the field is gone) that were not applied. */
  notApplied: string[];
};

export function prefillFrom(
  template: WorkItemTemplate,
  liveFields: CustomFieldAnswer[],
  /** Today, as `YYYY-MM-DD`, decided by the caller so the form and the page agree. */
  today: string,
  /** The types work can be created as today — `GET /work-items/vocabulary`. */
  creatableTypes: string[],
): TemplatePrefill {
  const fields = template.fields;
  const { custom, skipped } = applicableAnswers(fields, liveFields);

  // A type whose workflow was switched off after the template was written is
  // skipped and named, like a retired field: prefilled, it would make the form
  // unsubmittable for a reason the person cannot see.
  const typeApplies = fields.type === undefined || creatableTypes.includes(fields.type);

  if (!typeApplies && fields.type !== undefined) skipped.push(`type ${humanize(fields.type)}`);

  return {
    title: fields.title ?? "",
    description: fields.description ?? "",
    type: typeApplies ? (fields.type ?? "") : "",
    priority: fields.priority ?? "",
    estimate: fields.estimate_hours === undefined ? "" : String(fields.estimate_hours),
    dueAt: fields.due_in_days === undefined ? "" : addDays(today, fields.due_in_days),
    custom,
    notApplied: skipped,
  };
}

/**
 * A template's custom field answers split into the ones the create form would
 * accept today and the ones it would not — the field retired or removed, or
 * the option no longer offered.
 *
 * One function for both screens that ask. The editor and the picker each
 * deciding "stale" for themselves is two lists that must agree, and they would
 * stop agreeing the day somebody taught one of them about a new field type.
 */
export function applicableAnswers(
  fields: TemplateFields,
  liveFields: CustomFieldAnswer[],
): { custom: Record<string, string>; skipped: string[] } {
  const custom: Record<string, string> = {};
  const skipped: string[] = [];

  for (const [key, value] of Object.entries(fields.custom_fields ?? {})) {
    const field = liveFields.find((candidate) => candidate.key === key);

    if (field === undefined) {
      skipped.push(key);
      continue;
    }

    if (field.type === "select" && !field.options.includes(value)) {
      skipped.push(field.label);
      continue;
    }

    custom[key] = value;
  }

  return { custom, skipped };
}

/**
 * Calendar arithmetic on a date string, in UTC so no timezone can move it.
 *
 * `new Date("2026-09-27")` is midnight UTC; adding days in local time would
 * cross a day boundary for anyone east or west of it, which is everyone.
 */
export function addDays(date: string, days: number): string {
  const at = new Date(`${date}T00:00:00Z`);
  at.setUTCDate(at.getUTCDate() + days);

  return at.toISOString().slice(0, 10);
}

/** What a template fills in, in words, for a row in a list. */
export function describeFields(fields: TemplateFields, locale: Locale = "en"): string {
  // English unless the screen around it has been translated (ADR 0060).
  const t = translator(locale);
  const parts: string[] = [];

  if (fields.type) parts.push(humanize(fields.type));
  if (fields.priority) parts.push(t("tf.priority", { priority: priorityName(fields.priority, t) }));
  if (fields.estimate_hours !== undefined) parts.push(t("time.hours", { hours: fields.estimate_hours }));
  if (fields.due_in_days !== undefined) {
    parts.push(fields.due_in_days === 0 ? t("tf.sameDay") : t("tf.dueIn", { days: fields.due_in_days }));
  }
  if (fields.title) parts.push(t("tf.title", { title: fields.title }));
  if (fields.description) parts.push(t("tf.description"));

  const custom = Object.keys(fields.custom_fields ?? {}).length;
  if (custom > 0) parts.push(t.plural("tf.custom", custom));

  return parts.join(" · ");
}

/** `approval_work` is not a word. The API's vocabulary is not the user's. */
export function humanize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, " ");
}
