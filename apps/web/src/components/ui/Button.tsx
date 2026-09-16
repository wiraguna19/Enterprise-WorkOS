import Link from "next/link";
import { clsx } from "@/lib/clsx";
import type { ButtonHTMLAttributes, ReactNode } from "react";

/**
 * One primary action per screen (docs/09 §5). A 48px button belongs on a
 * marketing page; this is tooling.
 */
type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  /** Keyed off the VARIANTS map, so a new consequence cannot be added to the
   *  styles and forgotten in the type. */
  variant?: keyof typeof VARIANTS;
  size?: keyof typeof SIZES;
};

/**
 * Every variant carries a resting affordance (ADR 0024).
 *
 * `ghost` used to be bare text until hovered, which is what "Revoke", "End",
 * "Lift" and "Switch off" were rendered as — words in a table that gave no sign
 * they did anything. On a touch screen there is no hover at all, so the
 * affordance simply never appeared. It now rests with a border on a faintly
 * tinted surface: quieter than `secondary`, and unmistakably a control.
 */
/**
 * Colour follows CONSEQUENCE, not the verb (docs/09 §5, ADR 0024).
 *
 * The tempting scheme is one colour per kind of action — a colour for edit,
 * another for create, green for switch on, amber for switch off. A row of a
 * table then becomes a set of traffic lights, and once every button is
 * coloured, no button stands out. docs/09 has said since Phase 1 that colour
 * carries meaning and that ~90% of a screen is neutral.
 *
 * So there are three consequences and two hues, both already in the palette:
 *
 * - **accent** — it GIVES or STARTS something: grant, switch on, save, create.
 * - **destructive / danger** — it TAKES something away: end a session, revoke a
 *   grant, deny a permission, switch a rule off. Outlined for an action inside
 *   a row; solid only for a final confirmation, which in this product is
 *   erasing a person.
 * - **neutral** — everything else, which is most things: edit, explain,
 *   navigate.
 *
 * The distinction that was missing is the one that matters: "Switch off" and
 * "Edit" rendered identically, though one opens a form and the other stops the
 * product doing something for everybody.
 */
const VARIANTS = {
  primary: "bg-a-500 text-white hover:bg-a-700 disabled:bg-n-300 disabled:text-n-0",
  secondary:
    "bg-n-0 text-n-700 border border-n-300 hover:bg-n-50 hover:border-n-500 disabled:text-n-300 disabled:border-n-200",
  ghost:
    "bg-n-25 text-n-700 border border-n-200 hover:bg-n-50 hover:border-n-300 disabled:text-n-300 disabled:border-n-100",
  /** Gives or starts something, without claiming the screen's one primary. */
  affirmative:
    "bg-a-50 text-a-700 border border-a-500/40 hover:border-a-500 disabled:text-n-300 disabled:border-n-200 disabled:bg-n-25",
  /** Takes something away, in place. */
  destructive:
    "bg-s-danger/5 text-s-danger border border-s-danger/40 hover:bg-s-danger/10 hover:border-s-danger disabled:text-n-300 disabled:border-n-200 disabled:bg-n-25",
  /** The final confirmation of something irreversible. */
  danger: "bg-s-danger text-white hover:brightness-90 disabled:bg-n-300",
} as const;

/**
 * Two heights per size: finger first, pointer second.
 *
 * A 28px control is comfortable with a mouse and a coin toss with a thumb, and
 * approvals are done from a phone constantly (docs/08 §6). Rather than asking
 * every screen to remember a touch variant, the sizes themselves grow below
 * `md` — so the dense desktop tool stays dense and nothing on a phone is a
 * target you have to aim at.
 */
const SIZES = {
  sm: "h-9 px-2.5 text-caption md:h-7",
  md: "h-10 px-3 text-body md:h-8",
  lg: "h-11 px-4 text-body md:h-9",
} as const;

export function Button({
  variant = "secondary",
  size = "md",
  className,
  ...props
}: Props) {
  return (
    <button
      {...props}
      className={clsx(
        "inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md font-medium",
        "transition-colors duration-[120ms] ease-standard",
        // Keyboard focus was invisible on every variant: the browser default
        // outline is removed by the reset and nothing replaced it.
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-a-500/40",
        "disabled:cursor-not-allowed",
        VARIANTS[variant],
        SIZES[size],
        className,
      )}
    />
  );
}

/**
 * A link that looks like a button.
 *
 * Not a `<Button>` with an `onClick` that navigates: the thing that goes
 * somewhere should be an anchor, so it opens in a new tab, shows its
 * destination in the status bar, and works before the JavaScript arrives. The
 * board's "New work item" was a `<Button>` with no handler at all — a control
 * that looked finished from every angle and did nothing — and a link cannot
 * fail that way, because a link with no href does not render as one.
 */
export function ButtonLink({
  href,
  variant = "secondary",
  size = "md",
  className,
  children,
}: {
  href: string;
  variant?: keyof typeof VARIANTS;
  size?: keyof typeof SIZES;
  className?: string;
  children: ReactNode;
}) {
  return (
    <Link
      href={href}
      className={clsx(
        "inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md font-medium",
        "transition-colors duration-[120ms] ease-standard",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-a-500/40",
        VARIANTS[variant],
        SIZES[size],
        className,
      )}
    >
      {children}
    </Link>
  );
}
