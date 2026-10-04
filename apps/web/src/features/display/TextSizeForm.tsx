"use client";

import { useEffect, useState, useTransition } from "react";
import { Panel } from "@/components/ui/Panel";
import { useT } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages/en";
import { saveTextSize } from "./actions";
import { TEXT_SIZES, type TextSize } from "./text-size";

/** How much larger each step reads than the normal size, for the label. */
const SCALE: Record<TextSize, string> = { normal: "100%", large: "112%", larger: "125%" };

/**
 * Choosing a reading size.
 *
 * The page changes the moment a size is chosen, before the save returns: the
 * person deciding whether a size is right is deciding by looking at it, and a
 * preview box would show one sentence while the real question is whether the
 * board and the tables still read well. A refused save puts it back.
 */
export function TextSizeForm({ current }: { current: TextSize }) {
  const t = useT();
  const [chosen, setChosen] = useState<TextSize>(current);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [working, start] = useTransition();

  // The page follows the choice at once; a refused save sets it back, and
  // this follows that too.
  useEffect(() => {
    document.documentElement.dataset.textSize = chosen;
  }, [chosen]);

  function choose(size: TextSize): void {
    const previous = chosen;

    setChosen(size);
    setMessage(null);

    start(async () => {
      const { error } = await saveTextSize(size);

      if (error !== null) {
        setChosen(previous);
        setMessage({ tone: "error", text: error });

        return;
      }

      setMessage({ tone: "ok", text: t("display.saved") });
    });
  }

  return (
    <Panel id="text-size" title={t("display.size.title")} description={t("display.size.description")}>
      <fieldset disabled={working} className="space-y-2">
        <legend className="sr-only">{t("display.size.title")}</legend>

        {TEXT_SIZES.map((size) => (
          <label
            key={size}
            className="flex cursor-pointer items-center gap-3 rounded-sm border border-n-200 px-3 py-2 hover:bg-n-50 has-[:checked]:border-a-500 has-[:checked]:bg-a-50"
          >
            <input
              type="radio"
              name="text-size"
              value={size}
              checked={chosen === size}
              onChange={() => choose(size)}
            />
            <span className="min-w-0">
              <span className="block font-medium text-n-900">
                {t(`display.size.${size}` as MessageKey)}
                <span className="ml-2 font-normal tabular-nums text-n-500">{SCALE[size]}</span>
              </span>
              <span className="block text-caption text-n-500">
                {t(`display.size.${size}.hint` as MessageKey)}
              </span>
            </span>
          </label>
        ))}
      </fieldset>

      {message !== null && (
        <p
          role={message.tone === "error" ? "alert" : "status"}
          className={message.tone === "error" ? "mt-3 text-body-sm text-s-danger" : "mt-3 text-body-sm text-s-success"}
        >
          {message.text}
        </p>
      )}
    </Panel>
  );
}
