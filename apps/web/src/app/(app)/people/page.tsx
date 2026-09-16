import { ButtonLink } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { PeopleSearch } from "@/features/people/PeopleSearch";
import { PendingInvitations, type Pending } from "@/features/people/PendingInvitations";
import { PersonList } from "@/features/people/PersonList";
import type { Person } from "@/features/people/types";
import { api } from "@/lib/api";
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

  const query = (params.q ?? "").trim();
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
        title="People"
        description={
          query
            ? `${people.length} matching "${query}"`
            : `${people.length} active in ${me.organization.name}`
        }
        action={
          <div className="flex items-center gap-2">
            <PeopleSearch initialQuery={query} />
            {mayInvite && (
              <ButtonLink href="/people/invite" variant="primary">
                Invite someone
              </ButtonLink>
            )}
          </div>
        }
      />

      {mayInvite && <PendingInvitations invitations={invitations} />}

      {people.length === 0 ? (
        query ? (
          // Distinct from the empty organization below: "nobody matched" is a
          // dead end the user can back out of, and offering to invite someone
          // here would answer a question they did not ask.
          <EmptyState
            title="No one matched"
            description={`Nobody in ${me.organization.name} matches "${query}".`}
          />
        ) : (
          <EmptyState
            title="No one here yet"
            description="Invite colleagues to give them access to work, projects, and their own workspace."
            action={
              mayInvite ? (
                <ButtonLink href="/people/invite" variant="primary">
                  Invite someone
                </ButtonLink>
              ) : undefined
            }
          />
        )
      ) : (
        <PersonList people={people} timeZone={me.user.timezone} />
      )}
    </div>
  );
}
