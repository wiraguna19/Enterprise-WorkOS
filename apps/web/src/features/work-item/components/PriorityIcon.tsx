import { clsx } from "@/lib/clsx";
import type { Priority } from "../types";

/**
 * Icon plus text, never colour alone (docs/09 §2, §5).
 *
 * The glyph carries the shape, the colour reinforces it, and the accessible
 * name carries the meaning — so the control still reads correctly in
 * greyscale, at 200% zoom, and to a screen reader.
 */
const PRIORITY: Record<Priority, { glyph: string; tone: string; label: string }> = {
  urgent: { glyph: "⌃⌃", tone: "text-s-danger", label: "Urgent" },
  high: { glyph: "⌃", tone: "text-s-active", label: "High" },
  medium: { glyph: "–", tone: "text-n-500", label: "Medium" },
  low: { glyph: "⌄", tone: "text-n-300", label: "Low" },
};

export function PriorityIcon({
  priority,
  withLabel = false,
}: {
  priority: Priority;
  withLabel?: boolean;
}) {
  const { glyph, tone, label } = PRIORITY[priority];

  return (
    <span className={clsx("inline-flex items-center gap-1 text-caption", tone)}>
      <span aria-hidden className="font-semibold leading-none">
        {glyph}
      </span>
      {withLabel ? <span>{label}</span> : <span className="sr-only">{label} priority</span>}
    </span>
  );
}
