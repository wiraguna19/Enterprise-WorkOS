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

export function Badge({
  tone = "neutral",
  children,
}: {
  tone?: keyof typeof TONES;
  children: ReactNode;
}) {
  return (
    <span
      className={clsx(
        "inline-flex items-center rounded-md border px-1.5 py-0.5 text-micro font-medium",
        TONES[tone],
      )}
    >
      {children}
    </span>
  );
}
