import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { TemplateEditor } from "@/features/work-item/components/TemplateEditor";
import type { WorkItemTemplate, WorkVocabulary } from "@/features/work-item/templates";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * Work item templates (docs/10 Phase 7, ADR 0047).
 *
 * docs/10 lists templates twice, under Workflow and under Extensibility, and
 * the only one in the product lived inside a recurrence where no form could
 * reach it. This screen and its endpoints arrive together, and so does the
 * picker on the New work item form that reads them — a template nobody can
 * pick is a write path with no reader.
 *
 * Refused, not emptied, for somebody without the permission: an empty list
 * here would read as "this organization has no templates", which is somebody
 * else's organization described wrongly.
 */
export default async function TemplatesPage() {
  const me = await requireUser();

  if (!me.permissions.includes("work_item_template.manage")) notFound();

  // Translated (ADR 0060), with the editor it shares with project settings.
  const t = translator(asLocale(me.user.locale));

  const [templates, vocabulary, customFields] = await Promise.all([
    api<WorkItemTemplate[]>("/work-item-templates").then((r) => r.data),
    api<WorkVocabulary>("/work-items/vocabulary").then((r) => r.data),
    // The form's list — live fields, blank — because a template can only
    // prefill what the form can show.
    api<CustomFieldAnswer[]>("/work-items/fields").then((r) => r.data),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.templates.label")}
        description={t("tpl.page.description")}
      />

      <PageBody>
        <TemplateEditor
          // The organization's own. A project's templates are kept in that
          // project's settings, by the people who run it (ADR 0058).
          templates={templates.filter((template) => template.project === null)}
          vocabulary={vocabulary}
          customFields={customFields}
        />
      </PageBody>
    </div>
  );
}
