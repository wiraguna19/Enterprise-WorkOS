import { ButtonLink } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { ActiveSwitch } from "@/features/workflow/ActiveSwitch";
import { isBuildable } from "@/features/workflow/composable";
import { describeAction, describeCondition, describeTrigger } from "@/features/workflow/describe";
import type { Rule, RuleAction } from "@/features/workflow/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * What the system does on its own (docs/02 §7).
 *
 * Automation that cannot be read is automation nobody trusts, and until this
 * screen the only way to see which rules existed was to query the database —
 * `GET /workflow-rules` had no caller for three phases.
 *
 * Health is given the same weight as the rule itself, because a rule that has
 * been failing silently is the single thing an administrator most needs to see
 * and the thing least likely to announce itself: `failure_count` and
 * `disabled_reason` exist in the schema precisely because a rule can degrade
 * every workflow it touches while looking exactly like a working one.
 *
 * Each rule links to its own run log rather than an index of runs — a report is
 * reached from its subject.
 *
 * Editing is offered only for rules the builder can express: it composes a flat
 * "all of these hold" and two action types, and a form that opened a nested
 * predicate would save back less than the rule says. Switching off is offered
 * for every rule, because that one is the same act whatever the rule contains.
 */
export default async function RulesPage() {
  const me = await requireUser();

  const { data: rules } = await api<Rule[]>("/workflow-rules", { tags: ["workflow-rules"] });

  // One permission, two capabilities: the run log names subjects, and writing
  // changes what the product does for everybody.
  const mayManage = me.permissions.includes("workflow.manage");
  const unhealthy = rules.filter((rule) => !rule.health.healthy).length;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Automation rules"
        description={
          unhealthy > 0
            ? `${rules.length} rules · ${unhealthy} not running`
            : `${rules.length} rules · all running`
        }
        action={
          mayManage ? (
            <ButtonLink href="/settings/rules/new" variant="primary">
              New rule
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        {rules.length === 0 ? (
          <EmptyState
            title="Nothing is automated"
            description="A rule watches for something happening — work entering review, an item going overdue — and acts on it."
            action={
              mayManage ? (
                <ButtonLink href="/settings/rules/new" variant="primary">
                  Write the first one
                </ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <ul className="space-y-4">
            {rules.map((rule) => (
              <li key={rule.id}>
                <RuleCard rule={rule} mayManage={mayManage} />
              </li>
            ))}
          </ul>
        )}
      </PageBody>
    </div>
  );
}

function RuleCard({ rule, mayManage }: { rule: Rule; mayManage: boolean }) {
  const headingId = `rule-${rule.id}`;
  const conditions = describeCondition(rule.conditions);

  return (
    <Panel
      id={headingId}
      title={rule.name}
      description={rule.description}
      // Health sits in the header, where the eye lands first: a rule failing
      // silently is the thing an administrator most needs to see and the thing
      // least likely to announce itself (ADR 0024).
      actions={<Health rule={rule} />}
      footer={
        mayManage ? (
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <ButtonLink href={`/settings/rules/${rule.id}`} variant="ghost" size="sm">
              Why it did or did not fire
            </ButtonLink>

            {/* Absent, not disabled, for a rule this form cannot express: a
                control that opens a screen which then refuses is worse than one
                that was never offered. The edit page says why if reached. */}
            {isBuildable(rule) && (
              <ButtonLink href={`/settings/rules/${rule.id}/edit`} variant="ghost" size="sm">
                Edit
              </ButtonLink>
            )}

            <span className="ml-auto">
              <ActiveSwitch id={rule.id} name={rule.name} active={rule.is_active} />
            </span>
          </div>
        ) : undefined
      }
    >
      <dl className="space-y-2 text-body-sm">
        <div className="flex flex-wrap gap-x-3">
          <dt className="w-24 shrink-0 text-caption text-n-500">Runs</dt>
          <dd className="min-w-0 text-n-700">{describeTrigger(rule.trigger)}</dd>
        </div>

        <div className="flex flex-wrap gap-x-3">
          <dt className="w-24 shrink-0 text-caption text-n-500">If</dt>
          <dd className="min-w-0 text-n-700">
            {conditions ? (
              <ul className="space-y-0.5">
                {conditions.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            ) : (
              // The predicate uses something this build cannot put into words.
              // Printing the source is the honest answer: a description that
              // guesses is believed and never checked again.
              <Raw value={rule.conditions} />
            )}
          </dd>
        </div>

        <div className="flex flex-wrap gap-x-3">
          <dt className="w-24 shrink-0 text-caption text-n-500">Then</dt>
          <dd className="min-w-0 text-n-700">
            <ul className="space-y-0.5">
              {rule.actions.map((action, index) => (
                <li key={`${action.type}-${index}`}>
                  <Action action={action} />
                </li>
              ))}
            </ul>
          </dd>
        </div>
      </dl>

    </Panel>
  );
}

function Action({ action }: { action: RuleAction }) {
  const described = describeAction(action);

  // An action type this build does not register is a rule authored against a
  // newer vocabulary — or a rule that will throw when it next runs, which the
  // run log is where to confirm.
  if (!described) return <Raw value={action} />;

  return (
    <span>
      <span className="text-n-900">{described.verb}</span>
      {described.config.length > 0 && (
        <span className="text-n-500"> — {described.config.join(", ")}</span>
      )}
    </span>
  );
}

/**
 * A state, not an action — which is where colour is most at home (ADR 0024).
 *
 * "running" and "switched off" were both grey prose in the corner of a card,
 * and a rule that has been failing silently is the single thing an
 * administrator most needs to see.
 */
function Health({ rule }: { rule: Rule }) {
  if (rule.health.healthy) {
    return <Badge tone="success">running</Badge>;
  }

  // A rule somebody switched off is not a rule in trouble: it is doing exactly
  // what was asked of it, and colouring it like a failure trains people to
  // ignore the colour.
  if (!rule.is_active && rule.health.disabled_reason === null) {
    return <Badge>switched off</Badge>;
  }

  return (
    <Badge tone="danger">
      {rule.health.disabled_reason ?? `${rule.health.failure_count} recent failures`}
    </Badge>
  );
}

function Raw({ value }: { value: unknown }) {
  return (
    <pre className="overflow-x-auto whitespace-pre-wrap break-words font-mono text-micro text-n-700">
      {JSON.stringify(value)}
    </pre>
  );
}
