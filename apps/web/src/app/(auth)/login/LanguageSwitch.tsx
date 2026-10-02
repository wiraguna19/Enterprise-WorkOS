"use client";

import { useTransition } from "react";
import { LOCALE_NAME, LOCALES, type Locale } from "@/i18n/config";
import { useT } from "@/i18n/I18nProvider";
import { chooseSignInLanguage } from "@/features/auth/language-actions";

/**
 * The language of the sign-in screen, for somebody not signed in yet
 * (ADR 0060). Only this browser's choice; once signed in, the account's own
 * language takes over. Each name is written in itself, so the way back is
 * readable whichever language the page is in.
 */
export function LanguageSwitch({ current }: { current: Locale }) {
  const t = useT();
  const [working, start] = useTransition();

  return (
    <nav aria-label={t("login.language")} className="mt-8 flex justify-center gap-3 text-caption">
      {LOCALES.map((locale) =>
        locale === current ? (
          <span key={locale} lang={locale} aria-current="true" className="font-medium text-n-700">
            {LOCALE_NAME[locale]}
          </span>
        ) : (
          <button
            key={locale}
            type="button"
            lang={locale}
            disabled={working}
            onClick={() => start(() => chooseSignInLanguage(locale))}
            className="text-a-500 hover:text-a-700 hover:underline disabled:text-n-300"
          >
            {LOCALE_NAME[locale]}
          </button>
        ),
      )}
    </nav>
  );
}
