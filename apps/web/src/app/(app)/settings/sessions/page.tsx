import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { SessionList, type Session } from "@/features/sessions/SessionList";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDateTime } from "@/lib/format";

/**
 * Everything signed in as you (ADR 0023).
 *
 * `sessions` has recorded an address, a user agent, a creation time and a
 * last-used time since Phase 1, and nothing has ever read them — a complete
 * write path with no read path, the same shape as the audit log two slices ago
 * and worse here: this is the table that answers the question somebody asks
 * when they fear their account has been taken.
 *
 * No permission gates this page. It is not authority over anybody; it is an
 * account looking at itself, and every session it can name is the viewer's own.
 */
type Payload = {
  id: string;
  current: boolean;
  user_agent: string | null;
  ip_address: string | null;
  created_at: string;
  last_used_at: string | null;
};

export default async function SessionsPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const { data } = await api<Payload[]>("/auth/sessions");

  // Formatted HERE, in the viewer's own time zone, and handed over as strings:
  // a Server Component may give a Client Component values, never functions.
  const sessions: Session[] = data.map((session) => ({
    id: session.id,
    current: session.current,
    user_agent: session.user_agent,
    ip_address: session.ip_address,
    last_used:
      session.last_used_at === null
        ? t("sess.notSince")
        : formatDateTime(session.last_used_at, me.user.timezone, locale),
    started: formatDateTime(session.created_at, me.user.timezone, locale),
  }));

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.sessions.label")}
        description={t("sess.page.description")}
      />

      <PageBody>
        <SessionList sessions={sessions} />
      </PageBody>
    </div>
  );
}
