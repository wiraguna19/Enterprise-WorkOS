import type { MessageKey } from "./messages/en";
import type { Translator } from "./translate";

/**
 * Names for values the API sends as keys (ADR 0060).
 *
 * Only the vocabularies the interface owns. A value it does not know — a
 * priority an organization adds later, say — is shown humanized rather than
 * guessed at, which is also what English always did.
 */
const PRIORITIES = new Set(["urgent", "high", "medium", "low"]);

export function priorityName(value: string, t: Translator): string {
  return PRIORITIES.has(value) ? t(`priority.${value}` as MessageKey) : humanize(value);
}

const EMPLOYMENT = new Set(["full_time", "part_time", "contractor", "intern"]);

export function employmentName(value: string, t: Translator): string {
  return EMPLOYMENT.has(value) ? t(`employment.${value}` as MessageKey) : value.replace(/_/g, " ");
}

const PERSON_STATUSES = new Set(["invited", "suspended", "revoked"]);

export function personStatusName(value: string, t: Translator): string {
  return PERSON_STATUSES.has(value) ? t(`personStatus.${value}` as MessageKey) : value;
}

const SCOPES = new Set(["team", "department", "project"]);

/** "team", "department", "project" — what a grant or a denial is scoped to. */
export function scopeName(value: string, t: Translator): string {
  return SCOPES.has(value) ? t(`scope.${value}` as MessageKey) : value;
}

function humanize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, " ");
}

/**
 * A span of minutes in the largest whole unit: "8 hours", not "480 minutes".
 * Shared by the organization page and the session policy form, so the same
 * timeout cannot read one way in the summary and another in the picker.
 */
export function minutesName(minutes: number, t: Translator): string {
  if (minutes % 1440 === 0) return t.plural("unit.days", minutes / 1440);
  if (minutes % 60 === 0) return t.plural("unit.hours", minutes / 60);

  return t.plural("unit.minutes", minutes);
}
