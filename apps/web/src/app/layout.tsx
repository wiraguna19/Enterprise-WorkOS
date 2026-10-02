import type { Metadata } from "next";
import "@fontsource-variable/inter";
import "./globals.css";
import { requestLocale } from "@/i18n/server";

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
    <html lang={await requestLocale()} suppressHydrationWarning>
      <body>{children}</body>
    </html>
  );
}
