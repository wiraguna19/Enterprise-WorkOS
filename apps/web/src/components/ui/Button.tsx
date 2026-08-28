import { clsx } from "@/lib/clsx";
import type { ButtonHTMLAttributes } from "react";

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
    "bg-n-0 text-n-700 border border-n-200 hover:bg-n-50 disabled:text-n-300",
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
        "inline-flex items-center justify-center gap-1.5 rounded-sm font-medium",
        "transition-colors duration-[120ms] ease-standard",
        "disabled:cursor-not-allowed",
        VARIANTS[variant],
        SIZES[size],
        className,
      )}
    />
  );
}
