import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AcceptInviteForm } from "./AcceptInviteForm";
import { api } from "@/lib/api";
import { LanguageSwitch } from "../../login/LanguageSwitch";
import { I18nProvider } from "@/i18n/I18nProvider";
import { requestLocale } from "@/i18n/server";
import { translator } from "@/i18n/translate";

// The layout's template adds "— Work OS" itself.
export async function generateMetadata(): Promise<Metadata> {
  return { title: translator(await requestLocale())("join.metaTitle") };
}

/**
 * The only screen in this product used by somebody it has never met (ADR 0017).
 *
 * It shows what the preview endpoint returns and nothing more: the
 * organization's name and the address the invitation was sent to, so the person
 * can tell they are in the right place. Not who invited them, not the role, not
 * anything about who else is here — this page is reachable by anyone holding a
 * string.
 *
 * A token that is wrong, expired, revoked or already accepted all land here the
 * same way: 404. Telling them apart would be an oracle for guessing tokens.
 */
export default async function AcceptInvitePage({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;

  const invitation = await api<{ organization: string; email: string }>(
    `/invitations/${token}/preview`,
  )
    .then((r) => r.data)
    .catch(() => null);

  if (!invitation) notFound();

  const locale = await requestLocale();
  const t = translator(locale);

  // The address is bold inside the sentence; the sentence is cut at its
  // placeholder so each language keeps its own order around it.
  const [before, after] = t("join.invitedAs").split("{email}");

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
          <h1 className="text-h1 font-semibold text-n-900">
            {t("join.title", { org: invitation.organization })}
          </h1>
          <p className="mt-1 text-body text-n-500">
            {before}
            <strong className="text-n-700">{invitation.email}</strong>
            {after}
          </p>
        </div>

        <AcceptInviteForm token={token} />

        <LanguageSwitch current={locale} />
      </div>
    </main>
    </I18nProvider>
  );
}
