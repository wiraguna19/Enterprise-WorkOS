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

function humanize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, " ");
}
