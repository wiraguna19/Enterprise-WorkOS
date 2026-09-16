import { notFound } from "next/navigation";
import Link from "next/link";
import { ErasePerson } from "@/features/people/ErasePerson";
import { PageBody } from "@/components/ui/PageBody";
import { PersonDenials, type Denial } from "@/features/people/PersonDenials";
import {
  PersonAside,
  PersonEmployment,
  PersonIdentity,
  PersonWork,
} from "@/features/people/PersonProfile";
import { PersonRoles, type Grant, type Scope } from "@/features/people/PersonRoles";
import type { PersonDetail, Workload } from "@/features/people/types";
import type { WorkItem } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * A person's profile (docs/08 §2).
 *
 * Keyed by membership id rather than a handle: a person's name is not unique
 * and their email is not theirs to leak into a URL that gets pasted around.
 */
export default async function PersonPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  let person: PersonDetail;

  try {
    ({ data: person } = await api<PersonDetail>(`/people/${id}`));
  } catch (error) {
    // 404 covers both "no such person" and "not in your organization" —
    // deliberately indistinguishable (docs/05 §3).
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // Their work, filtered by the SERVER's view of what this viewer may see: the
  // endpoint applies work item visibility before the assignee filter, so this
  // cannot become a way to enumerate work in a private project through the
  // profile of someone who is on it (docs/06 §2).
  // Only asked for when the server already said this viewer may have it: the
  // permissions block is the server's own decision, echoed (docs/06 §2).
  const workload = person.permissions.view_workload
    ? await api<Workload>(`/people/${id}/workload`)
        .then((r) => r.data)
        .catch(() => null)
    : null;

  const { data: openWork } = await api<WorkItem[]>(
    `/work-items?filter[assignee_id]=${id}` +
      "&filter[state_category]=todo,in_progress,in_review,blocked" +
      "&sort=due_at&limit=10",
  ).catch(() => ({ data: [] as WorkItem[] }));

  // Their authority, and where it applies. Asked for only by somebody the
  // server would answer — a person's grants name the teams, projects and
  // departments they have power over, which is a map of the organization
  // `role.view` is trusted with (ADR 0016).
  const mayReadRoles = me.permissions.includes("role.view");

  const roles = mayReadRoles
    ? await api<{
        organization_wide: Array<{ key: string; name: string }>;
        scoped: Grant[];
        denials: Denial[];
      }>(`/people/${id}/roles`)
        .then((r) => r.data)
        .catch(() => null)
    : null;

  // Granting is refused on yourself, so the controls are not offered there
  // either — a control that opens and then refuses is worse than one that was
  // never there.
  // Not for an erased person. Granting Organization Admin to somebody the
  // product has just announced as erased is the shape of a control that
  // survived the state it was written for — and the API refuses it anyway, so
  // offering it would be a form whose only outcome is a refusal (ADR 0022).
  const mayManageRoles =
    me.permissions.includes("role.manage") &&
    me.membership.id !== id &&
    person.erased_at === null;

  const [scopes, assignable] = mayManageRoles
    ? await Promise.all([scopeOptions(), assignableRoles()])
    : [{ team: [], department: [], project: [] }, []];

  // The permission catalogue, for the denial form and the explainer. Served,
  // like every other vocabulary here: a form holding its own copy of the keys
  // is a form that cannot deny the one added last week (ADR 0020).
  const permissions = roles ? await permissionCatalogue() : [];

  return (
    <div className="space-y-3">
      <Link href="/people" className="text-body-sm text-n-500 hover:text-a-700">
        ← People
      </Link>

      <PersonIdentity person={person} />

      {/* Main and aside, from one place. The profile used to centre itself at
          `max-w-4xl` while the sections under it ran the full window, so this
          page had two left edges and a ragged right one (ADR 0024). */}
      <PageBody aside={<PersonAside person={person} workload={workload} />}>
        <PersonWork openWork={openWork} timeZone={me.user.timezone} />

        <PersonEmployment person={person} timeZone={me.user.timezone} />

        {roles && (
          <PersonRoles
            membershipId={id}
            organizationWide={roles.organization_wide}
            scoped={roles.scoped}
            mayManage={mayManageRoles}
            roles={assignable}
            scopes={scopes}
          />
        )}

        {roles && (
          <PersonDenials
            membershipId={id}
            denials={roles.denials}
            mayManage={mayManageRoles}
            permissions={permissions}
            scopes={scopes}
          />
        )}

        {person.permissions.erase && (
          <ErasePerson membershipId={id} name={person.name} erasedAt={person.erased_at} />
        )}
      </PageBody>
    </div>
  );
}

/**
 * The roles a grant may name, from the endpoint that owns the answer.
 *
 * Not a list in this file. Roles are ROWS — a customer's own role is as real as
 * a system one — and a form offering the four seeded keys is a form that cannot
 * grant the fifth. This codebase has paid for a list kept beside the thing that
 * owns it four times already.
 */
async function assignableRoles(): Promise<Array<{ key: string; name: string }>> {
  return api<Array<{ key: string; name: string }>>("/roles")
    .then((r) => r.data)
    .catch(() => []);
}

/** What a grant can be scoped TO, from the endpoints that own each list. */
async function scopeOptions(): Promise<{ team: Scope[]; department: Scope[]; project: Scope[] }> {
  const [team, department, project] = await Promise.all([
    api<Scope[]>("/teams?limit=100").then((r) => r.data).catch(() => []),
    api<Scope[]>("/departments?limit=100").then((r) => r.data).catch(() => []),
    api<Scope[]>("/projects?limit=100").then((r) => r.data).catch(() => []),
  ]);

  return { team, department, project };
}

/** Every permission this build has, from the endpoint that owns the list. */
async function permissionCatalogue(): Promise<Array<{ key: string; description: string | null }>> {
  return api<Array<{ key: string; description: string | null }>>("/permissions")
    .then((r) => r.data)
    .catch(() => []);
}
