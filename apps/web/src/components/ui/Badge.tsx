import { Icon, type IconName } from "@/components/ui/Icon";
import { clsx } from "@/lib/clsx";
import type { ReactNode } from "react";

/**
 * A short, coloured-by-meaning label (docs/09 §2, ADR 0024).
 *
 * Distinct from `StatusChip`, which says where a work item is in its workflow
 * and is specified in docs/09 §5. This is the generic one: revoked, erased,
 * this device, system role — states that are not workflow states and were being
 * rendered as ad-hoc `rounded-full` spans invented separately on four screens,
 * in three different greys.
 *
 * Colour carries meaning here, never decoration: `neutral` is the default and
 * most badges should stay that way.
 */
const TONES = {
  neutral: "border-n-200 bg-n-50 text-n-700",
  info: "border-s-info/30 bg-s-info/10 text-s-info",
  success: "border-s-success/30 bg-s-success/10 text-s-success",
  warning: "border-s-active/30 bg-s-active/10 text-s-active",
  danger: "border-s-danger/30 bg-s-danger/10 text-s-danger",
} as const;

/**
 * The filled version, for the ONE state on a screen that has to be seen from
 * across the room (ADR 0027).
 *
 * Bootstrap-style badge sets fill every tone, and then a row of them is a row
 * of traffic lights in which nothing is louder than anything else. Solid is
 * reserved: work that is late, a rule that is failing. If a screen shows two
 * solid badges, one of them is wrong.
 */
const SOLID = {
  neutral: "border-n-700 bg-n-700 text-n-0",
  info: "border-s-info bg-s-info text-n-0",
  success: "border-s-success bg-s-success text-n-0",
  warning: "border-s-active bg-s-active text-n-0",
  danger: "border-s-danger bg-s-danger text-n-0",
} as const;

export function Badge({
  tone = "neutral",
  icon,
  solid = false,
  children,
}: {
  tone?: keyof typeof TONES;
  /** A mark beside the word, never instead of it (docs/09 §5). */
  icon?: IconName;
  /** Reserved for the loudest state on the screen. */
  solid?: boolean;
  children: ReactNode;
}) {
  return (
    <span
      className={clsx(
        "inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-micro font-medium",
        solid ? SOLID[tone] : TONES[tone],
      )}
    >
      {icon && <Icon name={icon} />}
      {children}
    </span>
  );
}
