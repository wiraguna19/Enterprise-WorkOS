"use server";

import { revalidatePath } from "next/cache";
import { isLocale, type Locale } from "@/i18n/config";
import { rememberLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";

/**
 * Save the signed-in person's interface language (ADR 0060).
 *
 * To `users.locale`, so it follows them to every device, and to this
 * browser's cookie, so the sign-in screen agrees the next time they see it.
 * The whole app re-renders, because the shell around this page speaks the
 * language too.
 */
export async function saveLanguage(locale: Locale): Promise<{ error: string | null }> {
  // An argument to a server action is an input like any form field.
  if (!isLocale(locale)) return { error: "Unknown language." };

  try {
    await api("/auth/me", { method: "PATCH", body: { locale } });
  } catch (error) {
    if (error instanceof ApiRequestError) return { error: error.error.message };

    return { error: translator(locale)("common.unreachable") };
  }

  await rememberLocale(locale);
  revalidatePath("/", "layout");

  return { error: null };
}

/**
 * The sign-in screen's own choice, before anybody is signed in. Only the
 * browser's cookie: there is no account yet to save it to, and signing in
 * then shows the account's own choice.
 */
export async function chooseSignInLanguage(locale: Locale): Promise<void> {
  if (!isLocale(locale)) return;

  await rememberLocale(locale);
  revalidatePath("/login");
}
