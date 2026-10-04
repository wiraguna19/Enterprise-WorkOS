import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { SsoConnectionPanel, type SsoSettings } from "@/features/auth/SsoConnectionPanel";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * Single sign-on for this organization (ADR 0052).
 *
 * `notFound()` without `sso.manage`, like every settings page gated in the
 * index: whether the screen exists is not the page's to disclose, and a link
 * to a page that refuses reads as a broken product.
 */
export default async function SingleSignOnSettingsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("sso.manage")) notFound();

  const t = translator(asLocale(me.user.locale));

  const { data } = await api<SsoSettings>("/sso-connection");

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.sso.label")}
        description={t("sso.page.description")}
      />

      <PageBody>
        <SsoConnectionPanel settings={data} timeZone={me.user.timezone} />
      </PageBody>
    </div>
  );
}
