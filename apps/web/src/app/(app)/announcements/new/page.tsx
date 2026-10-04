import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { AnnouncementForm } from "@/features/announcements/AnnouncementForm";
import type { Audience } from "@/features/announcements/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Writing an announcement. The groups on offer are the ones the API says this
 * person may address — the same answer it gives when the form is sent.
 */
export default async function NewAnnouncementPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const audiences = await api<Audience[]>("/announcements/audiences").then((r) => r.data);

  return (
    <div className="space-y-5">
      <Breadcrumb
        locale={locale}
        items={[{ label: t("ann.title"), href: "/announcements" }, { label: t("ann.new.button") }]}
      />

      <PageHeader title={t("ann.new.button")} description={t("ann.new.description")} />

      {audiences.length === 0 ? (
        <EmptyState title={t("ann.cannot.title")} description={t("ann.cannot.body")} />
      ) : (
        <Panel id="compose" title={t("ann.compose")}>
          <AnnouncementForm audiences={audiences} />
        </Panel>
      )}
    </div>
  );
}
