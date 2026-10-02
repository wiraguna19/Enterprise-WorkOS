import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { clsx } from "@/lib/clsx";
import type { Priority } from "../types";

/**
 * Icon plus text, never colour alone (docs/09 §2, §5).
 *
 * The glyph carries the shape, the colour reinforces it, and the accessible
 * name carries the meaning — so the control still reads correctly in
 * greyscale, at 200% zoom, and to a screen reader.
 */
const PRIORITY: Record<Priority, { glyph: string; tone: string }> = {
  urgent: { glyph: "⌃⌃", tone: "text-s-danger" },
  high: { glyph: "⌃", tone: "text-s-active" },
  medium: { glyph: "–", tone: "text-n-500" },
  low: { glyph: "⌄", tone: "text-n-300" },
};

export function PriorityIcon({
  priority,
  withLabel = false,
  locale = "en",
}: {
  priority: Priority;
  withLabel?: boolean;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);
  const { glyph, tone } = PRIORITY[priority];
  const label = t(`priority.${priority}`);

  return (
    <span className={clsx("inline-flex items-center gap-1 text-caption", tone)}>
      <span aria-hidden className="font-semibold leading-none">
        {glyph}
      </span>
      {withLabel ? <span>{label}</span> : <span className="sr-only">{t("priority.srLabel", { label })}</span>}
    </span>
  );
}
