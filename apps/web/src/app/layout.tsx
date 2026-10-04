import type { Metadata } from "next";
import "@fontsource-variable/inter";
import "./globals.css";
import { cookies } from "next/headers";
import { requestLocale } from "@/i18n/server";
import { asTextSize, TEXT_SIZE_COOKIE } from "@/features/display/text-size";

/**
 * Inter is self-hosted (@fontsource-variable) rather than loaded from Google
 * Fonts. Three reasons, in order: the app must build and run in air-gapped and
 * proxied environments; a third-party font request is a CSP exception and a
 * privacy question that enterprise customers do ask about; and a self-hosted
 * variable font removes the flash of unstyled text entirely.
 */

export const metadata: Metadata = {
  title: { default: "Work OS", template: "%s — Work OS" },
  description: "Enterprise work management platform",
};

/**
 * `lang` from the browser's copy of the person's language (ADR 0060). Signed
 * in, the app layout corrects it from `users.locale` if the two disagree.
 */
export default async function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html
      lang={await requestLocale()}
      // From this browser's copy of the reading size, so the first paint is
      // already at it. The app layout corrects the copy from the account.
      data-text-size={asTextSize((await cookies()).get(TEXT_SIZE_COOKIE)?.value)}
      suppressHydrationWarning
    >
      <body>{children}</body>
    </html>
  );
}
