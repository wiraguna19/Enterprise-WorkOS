"use server";

import { cookies } from "next/headers";
import { revalidatePath } from "next/cache";
import { requestLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";
import { isTextSize, TEXT_SIZE_COOKIE, type TextSize } from "./text-size";

/** Save the reading size on the account, and on this browser for first paint. */
export async function saveTextSize(size: TextSize): Promise<{ error: string | null }> {
  const t = translator(await requestLocale());

  if (!isTextSize(size)) return { error: t("display.unknown") };

  try {
    await api("/auth/me", { method: "PATCH", body: { text_size: size } });
  } catch (error) {
    if (error instanceof ApiRequestError) return { error: error.error.message };

    return { error: t("common.unreachable") };
  }

  (await cookies()).set(TEXT_SIZE_COOKIE, size, {
    path: "/",
    sameSite: "lax",
    maxAge: 60 * 60 * 24 * 365,
  });
  revalidatePath("/", "layout");

  return { error: null };
}
