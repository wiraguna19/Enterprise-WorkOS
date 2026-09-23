import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { FieldEditor } from "@/features/custom-fields/FieldEditor";
import type { CustomField, FieldVocabulary } from "@/features/custom-fields/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The fields an organization declared for itself (ADR 0038).
 *
 * Custom fields have been in docs/02 §9, docs/03 §8, docs/05 §4, docs/08 §5 and
 * docs/12 since Phase 2, and nothing existed: grepping the repo for
 * `custom_field` returned nothing at all. This is the screen, and the endpoints
 * behind it, arriving together — an endpoint with no interface is the defect
 * this product has now found seventeen times.
 *
 * Work items first. Projects are in the same schema and reachable through the
 * same endpoints; the screen for them arrives with the project form that would
 * read them, rather than as a tab that declares fields nothing asks.
 */
export default async function FieldsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("custom_field.manage")) notFound();

  const [fields, vocabulary] = await Promise.all([
    api<CustomField[]>("/custom-fields/work_item").then((r) => r.data),
    api<FieldVocabulary>("/custom-fields/vocabulary").then((r) => r.data),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Custom fields"
        description="What this organization asks about a work item, on top of the fields every organization has."
      />

      <PageBody>
        <FieldEditor scope="work_item" fields={fields} types={vocabulary.types} />
      </PageBody>
    </div>
  );
}
