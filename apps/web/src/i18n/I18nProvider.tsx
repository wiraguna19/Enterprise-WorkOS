"use client";

import { createContext, useContext, useEffect, useMemo } from "react";
import { DEFAULT_LOCALE, LOCALE_COOKIE, type Locale } from "./config";
import { translator, type Translator } from "./translate";

/**
 * The signed-in person's language, for client components (ADR 0060).
 *
 * Given by the app layout from `users.locale`. It also brings the browser's
 * copy into line: `<html lang>` for assistive technology, and the cookie the
 * sign-in screen reads, so a language chosen on another device is the one
 * this browser shows when it is signed out next.
 */
const LocaleContext = createContext<Locale>(DEFAULT_LOCALE);

export function I18nProvider({ locale, children }: { locale: Locale; children: React.ReactNode }) {
  useEffect(() => {
    document.documentElement.lang = locale;
    document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=31536000; samesite=lax`;
  }, [locale]);

  return <LocaleContext value={locale}>{children}</LocaleContext>;
}

export function useLocale(): Locale {
  return useContext(LocaleContext);
}

export function useT(): Translator {
  const locale = useContext(LocaleContext);

  return useMemo(() => translator(locale), [locale]);
}
