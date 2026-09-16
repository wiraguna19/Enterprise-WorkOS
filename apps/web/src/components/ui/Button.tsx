import Link from "next/link";
import { clsx } from "@/lib/clsx";
import type { ButtonHTMLAttributes, ReactNode } from "react";

/**
 * One primary action per screen (docs/09 §5). A 48px button belongs on a
 * marketing page; this is tooling.
 */
type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "secondary" | "ghost" | "danger";
  size?: "sm" | "md" | "lg";
};

const VARIANTS = {
  primary: "bg-a-500 text-white hover:bg-a-700 disabled:bg-n-300",
  secondary:
    "bg-n-0 text-n-700 border border-n-300 hover:bg-n-50 disabled:text-n-300",
  ghost: "text-n-700 hover:bg-n-50 disabled:text-n-300",
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
        "inline-flex items-center justify-center gap-1.5 rounded-md font-medium",
        "transition-colors duration-[120ms] ease-standard",
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
        "inline-flex items-center justify-center gap-1.5 rounded-md font-medium",
        "transition-colors duration-[120ms] ease-standard",
        VARIANTS[variant],
        SIZES[size],
        className,
      )}
    >
      {children}
    </Link>
  );
}
