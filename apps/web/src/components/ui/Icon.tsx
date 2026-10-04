import { clsx } from "@/lib/clsx";

/**
 * The product's icons, drawn here (ADR 0027).
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
 *
 * The second group came with the navigation and the Home overview: one per
 * destination, so a long sidebar can be scanned by shape as well as read. Same
 * grid, same stroke, still drawn rather than installed, and still never alone —
 * every one sits beside its label.
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

  // ── Destinations ──────────────────────────────────────────────────────
  home: "M2.5 7.5L8 3l5.5 4.5M4 6.5V13h3v-3h2v3h3V6.5",
  list: "M6 4.5h7.5M6 8h7.5M6 11.5h7.5M2.5 4.5h.01M2.5 8h.01M2.5 11.5h.01",
  inbox: "M2 9l1.8-5.5h8.4L14 9v3.5H2V9zM2 9h3.5l1 1.5h3l1-1.5H14",
  layers: "M8 2.5l6 3-6 3-6-3 6-3zM2 8.5l6 3 6-3M2 11l6 3 6-3",
  folder: "M2 4h4l1.5 1.5H14v7.5H2V4z",
  megaphone: "M2.5 6.5v3h2l5 3v-9l-5 3h-2zM12 6a2.5 2.5 0 010 4",
  calendar: "M2.5 4h11v9.5h-11V4zM2.5 7h11M5.5 2.5v3M10.5 2.5v3",
  repeat: "M3 7a4.5 4.5 0 018.2-2.4M13 9a4.5 4.5 0 01-8.2 2.4M11.5 2.5v2.3H9.2M4.5 13.5v-2.3h2.3",
  trend: "M2 11.5l4-4 2.5 2.5L14 4.5M10.5 4.5H14V8",
  target: "M14 8A6 6 0 112 8a6 6 0 0112 0zM11 8a3 3 0 11-6 0 3 3 0 016 0zM8 8h.01",
  person: "M10.5 5a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zM3 13.5c0-2.5 2.2-4 5-4s5 1.5 5 4",
  people: "M8.25 4.75a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zM1.5 13c0-2.3 2-3.7 4.5-3.7s4.5 1.4 4.5 3.7M10.5 2.7a2.2 2.2 0 010 4.2M12 9.6c1.5.4 2.5 1.6 2.5 3.4",
  building: "M3 13.5V2.5h6.5v11M9.5 6H13v7.5M1.5 13.5h13M5 5h2M5 7.5h2M5 10h2",
  settings: "M10 8a2 2 0 11-4 0 2 2 0 014 0zM8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4",
  eye: "M1.5 8s2.5-4.5 6.5-4.5S14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8zM10 8a2 2 0 11-4 0 2 2 0 014 0z",
  arrowUpRight: "M5 11l6-6M6 5h5v5",
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
