import Link from "next/link";
import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { RuleForm } from "@/features/workflow/RuleForm";
import { isBuildable } from "@/features/workflow/composable";
import type { Rule, Vocabulary } from "@/features/workflow/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Editing a rule the builder can express.
 *
 * The engine reads nested `all`/`any`/`not` trees and five action types; the
 * builder composes a flat "all of these hold" with two of them. A form that
 * opened a rule outside that shape would delete the half it could not draw the
 * moment somebody pressed Save — so this page refuses, in as many words, and
 * shows the rule instead.
 *
 * **A placeholder that refuses does not rot.** `format=xlsx` answered 422
 * naming the missing writer until somebody built it, and that is the only kind
 * of gap in this codebase that has ever announced itself.
 */
export default async function EditRulePage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  if (!me.permissions.includes("workflow.manage")) notFound();

  const [vocabulary, rules] = await Promise.all([
    api<Vocabulary>("/workflow-vocabulary").then((r) => r.data),
    api<Rule[]>("/workflow-rules", { tags: ["workflow-rules"] }).then((r) => r.data),
  ]);

  const rule = rules.find((candidate) => candidate.id === id);

  if (!rule) notFound();

  return (
    <div className="space-y-5">
      <PageHeader title={rule.name} description="Changes apply to the next matching change." />

      {isBuildable(rule) ? (
        <RuleForm vocabulary={vocabulary} rule={rule} />
      ) : (
        <div className="max-w-prose space-y-3">
          <p className="text-body text-n-700">
            This rule says something this form cannot draw — a nested condition, or an action
            with no controls here. Opening it would mean saving back less than it says, so it
            stays as it is.
          </p>

          <pre className="overflow-x-auto whitespace-pre-wrap break-words border border-n-100 bg-n-50 p-3 font-mono text-micro text-n-700 rounded-md">
            {JSON.stringify({ conditions: rule.conditions, actions: rule.actions }, null, 2)}
          </pre>

          <p className="text-body-sm text-n-500">
            It can still be switched off from{" "}
            <Link href="/settings/rules" className="text-a-700 underline">
              the rules list
            </Link>
            , and edited through the API.
          </p>
        </div>
      )}
    </div>
  );
}
