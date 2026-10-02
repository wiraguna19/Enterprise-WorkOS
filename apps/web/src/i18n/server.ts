import { cookies, headers } from "next/headers";
import { asLocale, isLocale, LOCALE_COOKIE, type Locale } from "./config";

/**
 * The language for a screen with nobody signed in yet (ADR 0060): the copy of
 * the person's choice this browser holds, else what the browser asks for,
 * else English.
 *
 * Signed-in screens do not use this: they read `users.locale` from
 * `/auth/me`, which every one of them already awaits.
 */
export async function requestLocale(): Promise<Locale> {
  const stored = (await cookies()).get(LOCALE_COOKIE)?.value;

  if (isLocale(stored)) return stored;

  const accepted = (await headers()).get("accept-language") ?? "";

  // The first language named, not a weighted negotiation: two languages do not
  // need one.
  return asLocale(accepted.split(",")[0]?.trim().slice(0, 2).toLowerCase());
}

/** A year, and readable by the page: it is a preference, not a secret. */
export async function rememberLocale(locale: Locale): Promise<void> {
  (await cookies()).set(LOCALE_COOKIE, locale, {
    path: "/",
    sameSite: "lax",
    maxAge: 60 * 60 * 24 * 365,
  });
}
