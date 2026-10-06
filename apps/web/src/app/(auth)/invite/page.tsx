import type { Metadata } from "next";
import { InvitePreview } from "./InvitePreview";
import { LanguageSwitch } from "../login/LanguageSwitch";
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
 * The link is `/invite#<token>`. A fragment is never sent to a server, so the
 * token — the whole of the credential — does not land in any web server's,
 * proxy's or CDN's access log on the way here; the page reads it in the
 * browser and hands it to the server in a POST body.
 *
 * It shows what the preview endpoint returns and nothing more: the
 * organization's name and the address the invitation was sent to. A token that
 * is wrong, expired, revoked or already accepted all read the same way —
 * telling them apart would be an oracle for guessing tokens.
 */
export default async function AcceptInvitePage() {
  const locale = await requestLocale();

  return (
    <I18nProvider locale={locale}>
      <main className="flex min-h-dvh items-center justify-center px-4">
        <div className="w-full max-w-sm">
          <InvitePreview />

          <LanguageSwitch current={locale} />
        </div>
      </main>
    </I18nProvider>
  );
}
