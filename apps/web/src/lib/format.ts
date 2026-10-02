/**
 * Formatting is centralised because inconsistent dates across a dense
 * interface read as carelessness, and because timezone handling must be
 * explicit rather than ambient (docs/07 §1). The language is too (ADR 0060):
 * a screen that has been translated passes it, and one that has not stays in
 * the English it was written in.
 */

import { INTL_TAG, type Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

export function formatDate(value: string | null, timeZone?: string, locale: Locale = "en"): string {
  if (!value) return "—";

  return new Intl.DateTimeFormat(INTL_TAG[locale], {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone,
  }).format(new Date(value));
}

export function formatDateTime(
  value: string | null,
  timeZone?: string,
  locale: Locale = "en",
): string {
  if (!value) return "—";

  return new Intl.DateTimeFormat(INTL_TAG[locale], {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone,
  }).format(new Date(value));
}

/**
 * How long something has been waiting.
 *
 * A review queue answers "when was this submitted" with a timestamp, which is
 * the wrong question — the reviewer wants to know how long someone has been
 * blocked. The unit degrades with the magnitude so the number stays small and
 * comparable: "4h" and "3d" scan; "96 hours" does not.
 */
export function formatAge(
  value: string | null,
  now: Date = new Date(),
  locale: Locale = "en",
): string {
  if (!value) return "—";

  const t = translator(locale);
  const minutes = Math.floor((now.getTime() - new Date(value).getTime()) / 60000);

  if (minutes < 1) return t("age.justNow");
  if (minutes < 60) return t("age.minutes", { count: minutes });
  if (minutes < 60 * 24) return t("age.hours", { count: Math.floor(minutes / 60) });

  const days = Math.floor(minutes / (60 * 24));

  // Past a fortnight the exact count stops meaning anything and the fact that
  // it has been forgotten is the message.
  return days <= 14 ? t("age.days", { count: days }) : t("age.long");
}

/**
 * An age that says it is in the past, where the language needs it to.
 *
 * English reads "submitted 4h" as past already; Indonesian wants "4 jam lalu"
 * — and never "baru saja lalu", so "just now" is left as it is (ADR 0060).
 */
export function formatAgo(value: string | null, locale: Locale = "en"): string {
  const age = formatAge(value, new Date(), locale);

  if (!value || minutesSince(value) < 1) return age;

  return translator(locale)("age.ago", { age });
}

function minutesSince(value: string): number {
  return Math.floor((Date.now() - new Date(value).getTime()) / 60000);
}

export function formatHours(value: number | string | null): string {
  if (value === null) return "—";
  const hours = typeof value === "string" ? parseFloat(value) : value;
  return Number.isInteger(hours) ? `${hours}h` : `${hours.toFixed(1)}h`;
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

/**
 * A deterministic muted background per person, so avatars are stable across
 * sessions and never collide with status colour.
 */
export function avatarTone(id: string): string {
  const tones = [
    "bg-[#e8e3d9] text-[#5c5140]",
    "bg-[#dde4e8] text-[#3f5058]",
    "bg-[#e5dfe8] text-[#544060]",
    "bg-[#dde8de] text-[#3d5741]",
    "bg-[#e8dede] text-[#5e4040]",
  ];

  let hash = 0;
  for (let i = 0; i < id.length; i++) hash = (hash * 31 + id.charCodeAt(i)) >>> 0;

  return tones[hash % tones.length];
}
