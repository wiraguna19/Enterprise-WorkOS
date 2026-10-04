import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
import { AnnouncementCard } from "@/features/announcements/AnnouncementCard";
import { AnnouncementForm } from "@/features/announcements/AnnouncementForm";
import { MarkRead } from "@/features/announcements/MarkRead";
import { RemoveAnnouncement } from "@/features/announcements/RemoveAnnouncement";
import type { Announcement } from "@/features/announcements/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * One announcement, in full. For whoever may manage it, also who has seen it,
 * who has not yet acknowledged it, and the form to correct or retract it.
 *
 * Names are listed only for acknowledgement (ADR 0061). "41 of 50 have read
 * this" helps decide whether to say it again; a list of who has not opened a
 * casual notice is surveillance with no purpose.
 */
export default async function AnnouncementPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const announcement = await api<Announcement>(`/announcements/${id}`)
    .then((r) => r.data)
    .catch((error: unknown) => {
      if (error instanceof ApiRequestError && error.status === 404) notFound();
      throw error;
    });

  const stats = announcement.stats;
  const waiting = announcement.not_acknowledged ?? [];

  return (
    <div className="space-y-5">
      <Breadcrumb
        locale={locale}
        items={[{ label: t("ann.title"), href: "/announcements" }, { label: announcement.title }]}
      />

      <PageBody>
        <Panel id="announcement" title={t("ann.one")} bleed>
          <AnnouncementCard announcement={announcement} t={t} locale={locale} timeZone={me.user.timezone} full />
        </Panel>

        {announcement.can_manage && stats && (
          <Panel id="reach" title={t("ann.reach.title")} description={t("ann.reach.description")}>
            <dl className="grid grid-cols-2 gap-4 sm:max-w-md">
              <div>
                <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">{t("ann.col.read")}</dt>
                <dd className="text-h2 font-semibold tabular-nums text-n-900">
                  {t("ann.ofAudience", { count: stats.read, audience: stats.audience })}
                </dd>
              </div>
              {announcement.requires_acknowledgement && (
                <div>
                  <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                    {t("ann.col.acknowledged")}
                  </dt>
                  <dd className="text-h2 font-semibold tabular-nums text-n-900">
                    {t("ann.ofAudience", { count: stats.acknowledged, audience: stats.audience })}
                  </dd>
                </div>
              )}
            </dl>

            {announcement.requires_acknowledgement && (
              <div className="mt-4">
                <h3 className="text-body-sm font-semibold text-n-900">{t("ann.waiting.title")}</h3>
                {waiting.length === 0 ? (
                  <p className="text-body-sm text-n-500">{t("ann.waiting.none")}</p>
                ) : (
                  <ul aria-label={t("ann.waiting.title")} className="mt-1 flex flex-wrap gap-1.5">
                    {waiting.map((person) => (
                      <li
                        key={person.membership_id}
                        className="rounded-md border border-n-200 bg-n-25 px-2 py-0.5 text-body-sm text-n-700"
                      >
                        {person.name ?? t("ann.formerMember")}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </Panel>
        )}

        {announcement.can_manage && (
          <Panel id="edit" title={t("ann.edit.title")} actions={<RemoveAnnouncement id={announcement.id} />}>
            <AnnouncementForm existing={announcement} />
          </Panel>
        )}
      </PageBody>

      <MarkRead ids={announcement.read ? [] : [announcement.id]} />
    </div>
  );
}
