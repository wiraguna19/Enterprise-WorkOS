import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewDepartmentForm } from "@/features/organization/NewDepartmentForm";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

type Department = { id: string; name: string; depth: number };

export default async function NewDepartmentPage() {
  const me = await requireUser();

  if (!me.permissions.includes("department.create")) {
    // Said plainly, and named. "You do not have permission" tells a person
    // nothing they can act on; naming the permission tells their administrator
    // what to grant.
    return (
      <div className="space-y-5">
        <PageHeader title="New department" />
        <EmptyState
          title="You cannot create departments here"
          description="Creating a department needs the department.create permission. Your administrator grants it with a role."
        />
      </div>
    );
  }

  // A failed fetch is not a failed page: the parent is optional, so an empty
  // list degrades to "a top-level department" rather than blocking one from
  // existing.
  const departments = await api<Department[]>("/departments")
    .then((r) => r.data)
    .catch(() => [] as Department[]);

  return (
    <div className="space-y-5">
      <Link href="/departments" className="text-body-sm text-n-500 hover:text-a-700">
        ← Departments
      </Link>

      <PageHeader
        title="New department"
        description="A group for reporting: projects name one, and a person's reporting line follows it."
      />

      <NewDepartmentForm
        departments={departments.map((department) => ({
          id: department.id,
          label: `${"— ".repeat(department.depth)}${department.name}`,
        }))}
      />
    </div>
  );
}
