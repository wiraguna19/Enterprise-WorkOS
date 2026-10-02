/**
 * The interface languages (ADR 0060).
 *
 * Pure data, importable from server and client alike. The person's choice is
 * `users.locale`; `wos_locale` is the web's copy of it for the screens that
 * run before anybody is signed in.
 */
export const LOCALES = ["en", "id"] as const;

export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = "en";

export const LOCALE_COOKIE = "wos_locale";

/**
 * The tag `Intl` formats with. `en-GB`, not `en-US`, because day-month-year is
 * what this product has always printed (docs/07 §1).
 */
export const INTL_TAG: Record<Locale, string> = { en: "en-GB", id: "id-ID" };

/** Each language named in itself: somebody looking for theirs reads it that way. */
export const LOCALE_NAME: Record<Locale, string> = { en: "English", id: "Bahasa Indonesia" };

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (LOCALES as readonly string[]).includes(value);
}

/** Whatever arrived, a language the interface speaks. */
export function asLocale(value: unknown): Locale {
  return isLocale(value) ? value : DEFAULT_LOCALE;
}
