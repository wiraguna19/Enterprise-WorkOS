/**
 * Formatting is centralised because inconsistent dates across a dense
 * interface read as carelessness, and because timezone handling must be
 * explicit rather than ambient (docs/07 §1).
 */

export function formatDate(value: string | null, timeZone?: string): string {
  if (!value) return "—";

  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone,
  }).format(new Date(value));
}

export function formatDateTime(value: string | null, timeZone?: string): string {
  if (!value) return "—";

  return new Intl.DateTimeFormat("en-GB", {
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
export function formatAge(value: string | null, now: Date = new Date()): string {
  if (!value) return "—";

  const minutes = Math.floor((now.getTime() - new Date(value).getTime()) / 60000);

  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes}m`;
  if (minutes < 60 * 24) return `${Math.floor(minutes / 60)}h`;

  const days = Math.floor(minutes / (60 * 24));

  // Past a fortnight the exact count stops meaning anything and the fact that
  // it has been forgotten is the message.
  return days <= 14 ? `${days}d` : "over 2 weeks";
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
