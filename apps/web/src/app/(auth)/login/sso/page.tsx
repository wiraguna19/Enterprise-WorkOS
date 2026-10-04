import type { Metadata } from "next";
import { safeNextPath } from "@/lib/next-path";
import { SsoForm } from "./SsoForm";
import { LanguageSwitch } from "../LanguageSwitch";
import { I18nProvider } from "@/i18n/I18nProvider";
import { requestLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

// The layout's template adds "— Work OS"; a title that carried it too read
// "Single sign-on — Work OS — Work OS" in the tab.
export async function generateMetadata(): Promise<Metadata> {
  return { title: translator(await requestLocale())("ssologin.title") };
}

/**
 * What a failed round trip comes back with (ADR 0052).
 *
 * The route handlers send a CODE, never a sentence: a message taken from the
 * URL and printed on a sign-in page is a way to put words of anybody's
 * choosing on this product's letterhead. Anything not listed reads as the
 * generic failure.
 */
const FAILURES: Record<string, MessageKey> = {
  "auth.sso_expired": "ssologin.expired",
  "auth.sso_failed": "ssologin.failed",
  "auth.sso_no_account": "ssologin.noAccount",
};

export default async function SsoLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[]; error?: string | string[] }>;
}) {
  const { next, error } = await searchParams;
  const code = Array.isArray(error) ? error[0] : error;
  const locale = await requestLocale();
  const t = translator(locale);

  return (
    <I18nProvider locale={locale}>
    <main className="flex min-h-dvh items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8">
          <div className="mb-6 flex items-center gap-2">
            <span className="flex size-6 items-center justify-center rounded-sm bg-n-900 text-micro font-bold text-n-0">
              W
            </span>
            <span className="text-body font-semibold text-n-900">Work OS</span>
          </div>
          <h1 className="text-h1 font-semibold text-n-900">{t("ssologin.title")}</h1>
          <p className="mt-1 text-body text-n-500">
            {t("ssologin.subtitle")}
          </p>
        </div>

        <SsoForm
          next={safeNextPath(Array.isArray(next) ? next[0] : next)}
          failure={code === undefined ? null : t(FAILURES[code] ?? "ssologin.generic")}
        />

        <LanguageSwitch current={locale} />
      </div>
    </main>
    </I18nProvider>
  );
}
