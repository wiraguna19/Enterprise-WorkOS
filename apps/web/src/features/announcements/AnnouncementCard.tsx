import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import type { Locale } from "@/i18n/config";
import type { Translator } from "@/i18n/translate";
import { formatDate } from "@/lib/format";
import { AcknowledgeButton } from "./AcknowledgeButton";
import { audienceName } from "./audience";
import type { Announcement } from "./types";

/**
 * One announcement as its readers see it (ADR 0061).
 *
 * The body is rendered as TEXT with its line breaks kept — never as HTML. It is
 * somebody's words to a whole department, and a text box that rendered markup
 * would be a way to put a link that says one thing and goes somewhere else in
 * front of everyone at once.
 *
 * In a list the body is clamped to four lines and the title opens the whole
 * thing; on its own page it is shown in full.
 */
export function AnnouncementCard({
  announcement,
  t,
  locale,
  timeZone,
  full = false,
}: {
  announcement: Announcement;
  t: Translator;
  locale: Locale;
  timeZone?: string;
  full?: boolean;
}) {
  const headingId = `announcement-${announcement.id}`;

  return (
    <article aria-labelledby={headingId} className="space-y-1.5 px-4 py-3">
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <h3 id={headingId} className="text-body font-semibold text-n-900">
          {full ? (
            announcement.title
          ) : (
            <Link href={`/announcements/${announcement.id}`} className="hover:text-a-700 hover:underline">
              {announcement.title}
            </Link>
          )}
        </h3>
        {announcement.pinned && <Badge tone="info">{t("ann.pinned")}</Badge>}
        {!announcement.read && <Badge tone="warning">{t("ann.unread")}</Badge>}
        {announcement.expired && <Badge>{t("ann.expired")}</Badge>}
      </div>

      <p className="text-caption text-n-500">
        {t("ann.byline", {
          author: announcement.author.name ?? t("ann.formerMember"),
          audience: audienceName(announcement.audience, t),
          date: formatDate(announcement.published_at, timeZone, locale),
        })}
        {announcement.expires_at !== null && !announcement.expired && (
          <> · {t("ann.until", { date: formatDate(announcement.expires_at, timeZone, locale) })}</>
        )}
      </p>

      <p className={`whitespace-pre-line text-body-sm text-n-700 ${full ? "" : "line-clamp-4"}`}>
        {announcement.body}
      </p>

      {announcement.requires_acknowledgement && (
        <div className="pt-1">
          {announcement.acknowledged ? (
            <Badge tone="success" icon="check">{t("ann.acknowledged")}</Badge>
          ) : (
            <AcknowledgeButton id={announcement.id} />
          )}
        </div>
      )}
    </article>
  );
}
