import { clsx } from "@/lib/clsx";

/**
 * Six icons, drawn here (ADR 0027).
 *
 * This product had no icon set at all: priority is a text chevron, status is a
 * coloured dot, and everything else is a word. That was a reasonable place to
 * be — an icon that has to be learned is worse than a word that can be read —
 * but it left the states that repeat on every screen with nothing to be
 * recognised BY at a glance.
 *
 * Drawn rather than installed. The product needs six; a library brings a
 * thousand, a build step to shake them out again, and a second visual language
 * whose stroke weight and corner radius belong to somebody else. These are 12px
 * on a 16 grid, 1.5 stroke, `currentColor` — so a badge's tone colours its icon
 * without the icon knowing anything about tone.
 *
 * Always `aria-hidden`. Every one of these sits beside a word that says the
 * same thing: docs/09 §5 has required icon PLUS text since Phase 1, and an icon
 * alone is a rebus.
 */
const PATHS = {
  /** Done, running, allowed. */
  check: "M3.5 8.5l3 3 6-6.5",
  /** Something went wrong and is still wrong. */
  alert: "M8 5.5v3.5M8 11.5v.01M8 2.5L1.5 13.5h13L8 2.5z",
  /** Time ran out, or is running out. */
  clock: "M8 4.5V8l2.5 1.5M14 8A6 6 0 112 8a6 6 0 0112 0z",
  /** Taken away. */
  minus: "M4.5 8h7",
  /** Ended, closed, gone. */
  cross: "M4.5 4.5l7 7M11.5 4.5l-7 7",
  /** Shipped with the product; not a customer's own. */
  shield: "M8 14s5-2.2 5-6V4.2L8 2.5 3 4.2V8c0 3.8 5 6 5 6z",
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({ name, className }: { name: IconName; className?: string }) {
  return (
    <svg
      viewBox="0 0 16 16"
      className={clsx("size-3 shrink-0", className)}
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d={PATHS[name]} />
    </svg>
  );
}
