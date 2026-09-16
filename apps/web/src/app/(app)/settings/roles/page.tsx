import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { RoleEditor, type Permission, type Role } from "@/features/roles/RoleEditor";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Roles, and what is in them (ADR 0018).
 *
 * The list endpoint answers what roles exist; each role's contents and its
 * holder count come from its own endpoint, because a count per role on a list
 * of twenty is twenty queries nobody asked for. Fetched in parallel here, which
 * is the one place that knows how many there are.
 */
export default async function RolesPage() {
  const me = await requireUser();

  if (!me.permissions.includes("role.view")) notFound();

  const { data: keys } = await api<Array<{ key: string }>>("/roles");

  const [roles, permissions] = await Promise.all([
    Promise.all(keys.map((role) => api<Role>(`/roles/${role.key}`).then((r) => r.data))),
    api<Permission[]>("/permissions")
      .then((r) => r.data)
      .catch(() => [] as Permission[]),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Roles"
        description={`${roles.length} · a role can only contain permissions you hold yourself`}
      />

      <PageBody>
        <RoleEditor
          roles={roles}
          permissions={permissions}
          mayManage={me.permissions.includes("role.manage")}
        />
      </PageBody>
    </div>
  );
}
