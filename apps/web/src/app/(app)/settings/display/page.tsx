import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { TextSizeForm } from "@/features/display/TextSizeForm";
import { asTextSize } from "@/features/display/text-size";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/** How the interface reads for this person: its size, for now (docs/09 §2). */
export default async function DisplayPage() {
  const me = await requireUser();
  const t = translator(asLocale(me.user.locale));

  return (
    <div className="space-y-5">
      <PageHeader title={t("settings.display.label")} description={t("display.description")} />

      <PageBody>
        <TextSizeForm current={asTextSize(me.user.text_size)} />
      </PageBody>
    </div>
  );
}
