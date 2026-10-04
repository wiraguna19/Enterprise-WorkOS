import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { TwoFactorPanel } from "@/features/auth/TwoFactorPanel";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * A second factor for this account (ADR 0030).
 *
 * No permission gates it, like the session list beside it: this is an account
 * deciding about itself, and a key that could be withheld would mean an
 * administrator refusing somebody their own security settings.
 *
 * `mfa_enabled` has been in `/auth/me` since Phase 1 and nothing read it,
 * because nothing could turn it on.
 */
export default async function TwoFactorPage() {
  // The one page a confined person may see — so the one page that says so
  // rather than sending them somewhere else (ADR 0033).
  const me = await requireUser({ allowUnenrolled: true });
  const t = translator(asLocale(me.user.locale));

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.twoFactor.label")}
        description={
          me.organization.requires_second_factor && !me.user.mfa_enabled
            ? t("tfa.page.required", { org: me.organization.name })
            : t("tfa.page.description")
        }
      />

      <PageBody>
        <TwoFactorPanel enabled={me.user.mfa_enabled} />
      </PageBody>
    </div>
  );
}
