"use client";

import { useState, useTransition } from "react";
import { Panel } from "@/components/ui/Panel";
import { LOCALE_NAME, LOCALES, type Locale } from "@/i18n/config";
import { useT } from "@/i18n/I18nProvider";
import { translator } from "@/i18n/translate";
import { saveLanguage } from "./language-actions";

/**
 * Two choices, saved the moment one is picked (ADR 0060).
 *
 * No Save button: there is nothing else on the form to finish first, and the
 * page answering in the new language at once is the confirmation. Each name is
 * written in its own language and marked with `lang`, so a screen reader
 * pronounces "Bahasa Indonesia" as Indonesian whatever the page is in.
 */
export function LanguageForm({ current }: { current: Locale }) {
  const t = useT();
  const [chosen, setChosen] = useState<Locale>(current);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [working, start] = useTransition();

  function choose(locale: Locale): void {
    setChosen(locale);
    setMessage(null);

    start(async () => {
      const { error } = await saveLanguage(locale);

      if (error !== null) {
        setChosen(current);
        setMessage({ tone: "error", text: error });

        return;
      }

      // Said in the language just chosen: the page around it is about to be.
      setMessage({
        tone: "ok",
        text: translator(locale)("language.saved", { language: LOCALE_NAME[locale] }),
      });
    });
  }

  return (
    <Panel id="interface-language" title={t("language.panel.title")} description={t("language.panel.description")}>
      <fieldset disabled={working} className="space-y-2">
        <legend className="sr-only">{t("language.panel.title")}</legend>

        {LOCALES.map((locale) => (
          <label
            key={locale}
            className="flex cursor-pointer items-center gap-3 rounded-sm border border-n-200 px-3 py-2 hover:bg-n-50 has-[:checked]:border-a-500 has-[:checked]:bg-a-50"
          >
            <input
              type="radio"
              name="locale"
              value={locale}
              checked={chosen === locale}
              onChange={() => choose(locale)}
            />
            <span lang={locale} className="font-medium text-n-900">
              {LOCALE_NAME[locale]}
            </span>
            {current === locale && <span className="ml-auto text-caption text-n-500">{t("language.current")}</span>}
          </label>
        ))}
      </fieldset>

      {message !== null && (
        <p
          role={message.tone === "error" ? "alert" : "status"}
          lang={message.tone === "ok" ? chosen : undefined}
          className={message.tone === "error" ? "mt-3 text-body-sm text-s-danger" : "mt-3 text-body-sm text-s-success"}
        >
          {message.text}
        </p>
      )}
    </Panel>
  );
}
