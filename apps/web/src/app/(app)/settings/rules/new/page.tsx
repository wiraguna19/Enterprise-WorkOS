import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { RuleForm } from "@/features/workflow/RuleForm";
import type { Vocabulary } from "@/features/workflow/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Authoring a rule.
 *
 * A page rather than a dialog, for the reason the New work item form is one: it
 * is a link somebody can be sent, and a form this tall in a dialog is a form
 * with its own scrollbar.
 *
 * 404 rather than a disabled form for somebody without `workflow.manage` — they
 * cannot reach it from the list either, and a screen that renders itself only
 * to refuse at the end is the shape this codebase keeps deleting.
 */
export default async function NewRulePage() {
  const me = await requireUser();

  if (!me.permissions.includes("workflow.manage")) notFound();

  const { data: vocabulary } = await api<Vocabulary>("/workflow-vocabulary");

  return (
    <div className="space-y-5">
      <PageHeader
        title="New rule"
        description="It runs on every change that matches, for everybody."
      />

      <RuleForm vocabulary={vocabulary} />
    </div>
  );
}
