import type { Metadata } from "next";
import { I18nProvider } from "@/i18n/I18nProvider";
import { requestLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";
import { safeNextPath } from "@/lib/next-path";
import { LanguageSwitch } from "./LanguageSwitch";
import { LoginForm } from "./LoginForm";

/**
 * In the language this browser asked for (ADR 0060). Nobody is signed in, so
 * there is no `users.locale` to read yet: the cookie, else the browser's own
 * preference. The root layout's title template adds "— Work OS".
 */
export async function generateMetadata(): Promise<Metadata> {
  return { title: translator(await requestLocale())("login.metaTitle") };
}

/**
 * Deliberately plain. A login screen is a door, not a landing page: no hero,
 * no gradient, no product marketing (docs/09 §1).
 */
/**
 * `searchParams` is a promise in Next 16, and the `next` the proxy wrote is
 * read HERE rather than in the client component: the value is validated on the
 * server before it is ever rendered into the page (ADR 0032).
 */
export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  const { next } = await searchParams;
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
          <h1 className="text-h1 font-semibold text-n-900">{t("login.title")}</h1>
          <p className="mt-1 text-body text-n-500">{t("login.subtitle")}</p>
        </div>

        <LoginForm next={safeNextPath(Array.isArray(next) ? next[0] : next)} />

        <LanguageSwitch current={locale} />
      </div>
    </main>
    </I18nProvider>
  );
}
