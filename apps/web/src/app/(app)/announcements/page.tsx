import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import { ButtonLink } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { AnnouncementCard } from "@/features/announcements/AnnouncementCard";
import { audienceName } from "@/features/announcements/audience";
import { MarkRead } from "@/features/announcements/MarkRead";
import type { Announcement, Audience } from "@/features/announcements/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDate } from "@/lib/format";

/**
 * What has been said to this person's groups, and — for whoever publishes —
 * what they have said and who has seen it (ADR 0061).
 *
 * The feed is pinned-first and then newest, and stops at what has expired.
 * The second panel is where expired announcements are found again, by the
 * people who may change them; a reader has no use for a notice that is over.
 */
export default async function AnnouncementsPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const timeZone = me.user.timezone;

  const [feed, managed, audiences] = await Promise.all([
    api<Announcement[]>("/announcements").then((r) => r.data),
    // Caught to empty: somebody who manages nothing gets no second panel,
    // which is the same thing the API would say.
    api<Announcement[]>("/announcements?manage=1")
      .then((r) => r.data)
      .catch(() => [] as Announcement[]),
    api<Audience[]>("/announcements/audiences")
      .then((r) => r.data)
      .catch(() => [] as Audience[]),
  ]);

  const unread = feed.filter((announcement) => !announcement.read).map((announcement) => announcement.id);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("ann.title")}
        description={t("ann.description")}
        action={
          audiences.length > 0 ? (
            <ButtonLink href="/announcements/new" variant="primary">
              {t("ann.new.button")}
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        <Panel id="feed" title={t("ann.feed.title")} description={t("ann.feed.description")} bleed>
          {feed.length === 0 ? (
            <div className="p-4">
              <EmptyState title={t("ann.feed.empty.title")} description={t("ann.feed.empty.body")} />
            </div>
          ) : (
            <div className="divide-y divide-n-200">
              {feed.map((announcement) => (
                <AnnouncementCard
                  key={announcement.id}
                  announcement={announcement}
                  t={t}
                  locale={locale}
                  timeZone={timeZone}
                />
              ))}
            </div>
          )}
        </Panel>

        {managed.length > 0 && (
          <Panel id="managed" title={t("ann.managed.title")} description={t("ann.managed.description")} bleed>
            <DataTable caption={t("ann.managed.title")}>
              <THead>
                <Tr>
                  <Th>{t("ann.col.title")}</Th>
                  <Th width="w-40">{t("ann.col.audience")}</Th>
                  <Th width="w-28">{t("ann.col.published")}</Th>
                  <Th width="w-24" align="right">{t("ann.col.read")}</Th>
                  <Th width="w-28" align="right">{t("ann.col.acknowledged")}</Th>
                </Tr>
              </THead>
              <TBody>
                {managed.map((announcement) => (
                  <Tr key={announcement.id}>
                    <Td>
                      <span className="flex min-w-0 items-center gap-2">
                        <Link
                          href={`/announcements/${announcement.id}`}
                          className="min-w-0 truncate font-medium text-n-900 hover:underline"
                        >
                          {announcement.title}
                        </Link>
                        {announcement.pinned && <Badge tone="info">{t("ann.pinned")}</Badge>}
                        {announcement.expired && <Badge>{t("ann.expired")}</Badge>}
                      </span>
                    </Td>
                    <Td muted>{audienceName(announcement.audience, t)}</Td>
                    <Td muted>{formatDate(announcement.published_at, timeZone, locale)}</Td>
                    <Td align="right">
                      <span className="tabular-nums">
                        {announcement.stats
                          ? t("ann.ofAudience", { count: announcement.stats.read, audience: announcement.stats.audience })
                          : "—"}
                      </span>
                    </Td>
                    <Td align="right">
                      <span className="tabular-nums">
                        {announcement.requires_acknowledgement && announcement.stats
                          ? t("ann.ofAudience", {
                              count: announcement.stats.acknowledged,
                              audience: announcement.stats.audience,
                            })
                          : "—"}
                      </span>
                    </Td>
                  </Tr>
                ))}
              </TBody>
            </DataTable>
          </Panel>
        )}
      </PageBody>

      <MarkRead ids={unread} />
    </div>
  );
}
