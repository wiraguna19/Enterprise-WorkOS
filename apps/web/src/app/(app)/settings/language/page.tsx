import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { LanguageForm } from "@/features/auth/LanguageForm";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * Settings → Language (ADR 0060).
 *
 * Ungated, like Signed in and API tokens: it is a person's own account, and
 * there is nobody it would make sense to refuse.
 */
export default async function LanguagePage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      <PageHeader title={t("language.title")} description={t("language.description")} />

      <PageBody>
        <LanguageForm current={locale} />
      </PageBody>
    </div>
  );
}
