import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
import { PageHeader } from "@/components/ui/PageHeader";
import { DepartmentRow, type DepartmentNode } from "@/features/organization/DepartmentRow";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The organization's structure (docs/02 §4).
 *
 * Phase 2 shipped creating, renaming and moving a department, and for six
 * phases nothing in the product could do any of the three. The list itself has
 * been read since the New project form arrived — which is why the reachability
 * guard exempted the writes per VERB and kept watching the read.
 *
 * A tree rendered as an indented list rather than as nested `<ul>`s. The API
 * returns rows carrying their own `depth`, already ordered; rebuilding a
 * hierarchy here would be a second implementation of an order the server
 * already decided, and the two would disagree the first time a move happened
 * while somebody was reading.
 */
type Department = DepartmentNode & { permissions?: Record<string, boolean> };

export default async function DepartmentsPage() {
  const me = await requireUser();

  // The nav hides this entry without the permission; the PAGE has to refuse it
  // too. A URL is typed, pasted and bookmarked, and until now the two screens
  // whose reads have no fallback answered a 403 with "Something went wrong"
  // while the two that do fall back answered with an empty state — which is
  // worse, because "no departments yet" is a confident lie about somebody
  // else's organization. 404 rather than 403, like every other refusal in this
  // product: whether the thing exists is not this page's to disclose.
  if (!me.permissions.includes("department.view")) notFound();


  const departments = await api<Department[]>("/departments")
    .then((r) => r.data)
    .catch(() => [] as Department[]);

  const mayCreate = me.permissions.includes("department.create");

  return (
    <div className="space-y-5">
      <PageHeader
        title="Departments"
        description={`${departments.length} in ${me.organization.name}`}
        action={
          mayCreate ? (
            <ButtonLink href="/departments/new" variant="primary">
              New department
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        {departments.length === 0 ? (
          <EmptyState
            title="No departments yet"
            description="Departments are how work, people and projects are grouped for reporting. A project can name one, and a person's reporting line follows it."
            action={
              mayCreate ? (
                <ButtonLink href="/departments/new" variant="primary">
                  Create the first department
                </ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Panel
            id="departments"
            title="Departments"
            description="Indentation is the reporting line. Moving one moves everything under it."
            bleed
          >
            <ul>
          {departments.map((department) => (
            <DepartmentRow
              key={department.id}
              department={department}
              // Itself excluded, because a department cannot report into
              // itself. Its descendants are NOT excluded here: whether a move
              // makes a cycle is decided by the domain inside the transaction
              // that performs it, with the row locks that answer needs, and a
              // second opinion computed on this side would be wrong exactly
              // when two people move departments at once.
              options={departments
                .filter((option) => option.id !== department.id)
                .map((option) => ({
                  id: option.id,
                  label: `${"— ".repeat(option.depth)}${option.name}`,
                }))}
            />
            ))}
            </ul>
          </Panel>
        )}
      </PageBody>
    </div>
  );
}
