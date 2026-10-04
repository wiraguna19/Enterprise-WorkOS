import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewDepartmentForm } from "@/features/organization/NewDepartmentForm";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

type Department = { id: string; name: string; depth: number };

export default async function NewDepartmentPage() {
  const me = await requireUser();
  const t = translator(asLocale(me.user.locale));

  if (!me.permissions.includes("department.create")) {
    // Said plainly, and named. "You do not have permission" tells a person
    // nothing they can act on; naming the permission tells their administrator
    // what to grant.
    return (
      <div className="space-y-5">
        <PageHeader title={t("depts.new")} />
        <EmptyState
          title={t("dnew.cannot.title")}
          description={t("dnew.cannot.body")}
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
        {t("dnew.back")}
      </Link>

      <PageHeader
        title={t("depts.new")}
        description={t("dnew.description")}
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
