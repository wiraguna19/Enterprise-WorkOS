import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { EditWorkItemForm } from "@/features/work-item/components/EditWorkItemForm";
import type { MilestoneOption } from "@/features/work-item/actions";
import type { WorkVocabulary } from "@/features/work-item/templates";
import type { WorkItem } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * Edit a work item (docs/08 §4).
 *
 * The item is fetched here, on the request that renders the form, so
 * `lock_version` is as fresh as it can be: a version read a minute ago is a
 * conflict waiting to be reported for no reason.
 *
 * A separate page rather than inline fields on the item, for the same reason
 * the create form is one — it renders complete on the server, survives a
 * reload, and is a link. Inline editing would also mean deciding what a
 * half-typed title does to the page around it.
 */
export default async function EditWorkItemPage({
  params,
}: {
  params: Promise<{ reference: string }>;
}) {
  const [me, { reference }] = await Promise.all([requireUser(), params]);

  let item: WorkItem;

  try {
    ({ data: item } = await api<WorkItem>(`/work-items/${reference}`));
  } catch (error) {
    // 404 covers both "does not exist" and "not visible to you", deliberately
    // indistinguishable (docs/05 §3).
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // Translated (ADR 0060).
  const t = translator(asLocale(me.user.locale));

  if (!(item.permissions.update ?? false)) {
    return (
      <div className="space-y-5">
        <PageHeader title={item.reference} />
        <EmptyState
          title={t("wedit.cannot.title")}
          description={t("wedit.cannot.body")}
        />
      </div>
    );
  }

  // The priority scale is served, not written out here (ADR 0047). A failed
  // read leaves the form with the item's own value only — never a guess.
  const [priorities, milestones] = await Promise.all([
    api<WorkVocabulary>("/work-items/vocabulary")
      .then((r) => r.data.priorities)
      .catch(() => [] as string[]),
    // The item's own project's milestones — the only ones it may belong to.
    item.project === null
      ? Promise.resolve(null)
      : api<MilestoneOption[]>(`/projects/${item.project.key}/milestones`)
          .then((r) => r.data)
          .catch(() => [] as MilestoneOption[]),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("wedit.title", { reference: item.reference })}
        description={t("wedit.description")}
      />

      <EditWorkItemForm
        reference={item.reference}
        priorities={priorities}
        initial={{
          title: item.title,
          description: item.description ?? "",
          priority: item.priority,
          start_date: item.start_date,
          due_at: item.due_at,
          // A number in the API, a string in an input. Converted once, here,
          // rather than in the form's every comparison.
          estimate_hours: item.estimate_hours === null ? null : String(item.estimate_hours),
          milestone_id: item.milestone_id ?? null,
        }}
        customFields={item.custom_fields ?? []}
        lockVersion={item.lock_version}
        canDelete={item.permissions.delete ?? false}
        timeZone={me.user.timezone}
        milestones={milestones}
      />
    </div>
  );
}
