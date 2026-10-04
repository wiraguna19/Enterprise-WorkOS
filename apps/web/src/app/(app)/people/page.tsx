import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { PeopleSearch } from "@/features/people/PeopleSearch";
import { PendingInvitations, type Pending } from "@/features/people/PendingInvitations";
import { PersonList } from "@/features/people/PersonList";
import type { Person } from "@/features/people/types";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * The people directory (docs/08 §2).
 *
 * A Server Component: the list arrives with the HTML. Rendering decisions live
 * in features/people; this file composes and nothing more (docs/04 §4).
 */
export default async function PeoplePage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  // The nav hides this entry without the permission; the PAGE has to refuse it
  // too. A URL is typed, pasted and bookmarked, and until now the two screens
  // whose reads have no fallback answered a 403 with "Something went wrong"
  // while the two that do fall back answered with an empty state — which is
  // worse, because "no departments yet" is a confident lie about somebody
  // else's organization. 404 rather than 403, like every other refusal in this
  // product: whether the thing exists is not this page's to disclose.
  if (!me.permissions.includes("person.view")) notFound();


  const query = (params.q ?? "").trim();
  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const search = query.length >= 2 ? `&q=${encodeURIComponent(query)}` : "";

  const { data: people } = await api<Person[]>(`/people?limit=100${search}`);

  // Invitations sit with the directory because they answer the same question —
  // who is here — one row earlier. `person.invite` is what the endpoint
  // requires, so the list is not asked for by anybody who would be refused it.
  const mayInvite = me.permissions.includes("person.invite");

  const invitations = mayInvite
    ? await api<Pending[]>("/invitations")
        .then((r) => r.data)
        .catch(() => [])
    : [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("nav.people")}
        description={
          query
            ? t("people.matching", { count: people.length, query })
            : t("projects.summary", { count: people.length, org: me.organization.name })
        }
        action={
          <div className="flex items-center gap-2">
            <PeopleSearch initialQuery={query} />
            {mayInvite && (
              <ButtonLink href="/people/invite" variant="primary">
                {t("people.invite")}
              </ButtonLink>
            )}
          </div>
        }
      />

      <PageBody>
        {mayInvite && <PendingInvitations invitations={invitations} />}

        {people.length === 0 ? (
          query ? (
            // Distinct from the empty organization below: "nobody matched" is a
            // dead end the user can back out of, and offering to invite someone
            // here would answer a question they did not ask.
            <EmptyState
              title={t("people.noMatch.title")}
              description={t("people.noMatch.body", { org: me.organization.name, query })}
            />
          ) : (
            <EmptyState
              title={t("people.empty.title")}
              description={t("people.empty.body")}
              action={
                mayInvite ? (
                  <ButtonLink href="/people/invite" variant="primary">
                    {t("people.invite")}
                  </ButtonLink>
                ) : undefined
              }
            />
          )
        ) : (
          <Panel
            id="directory"
            title={t("people.directory")}
            description={t.plural("people.count", people.length)}
            bleed
          >
            <PersonList people={people} timeZone={me.user.timezone} locale={locale} />
          </Panel>
        )}
      </PageBody>
    </div>
  );
}
