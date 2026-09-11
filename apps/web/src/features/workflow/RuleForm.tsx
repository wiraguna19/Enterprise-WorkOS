"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { createRule, saveRule, type RuleInput } from "./actions";
import { BUILDABLE_ACTIONS, leaves, type Leaf } from "./composable";
import { describeTrigger } from "./describe";
import type { Rule, Vocabulary } from "./types";

/**
 * The rule builder (docs/10 Phase 7).
 *
 * Deliberately not a predicate editor. The engine reads nested `all`/`any`/
 * `not` trees and five action types; this form composes ONE shape — a flat
 * "all of these must hold" — and refuses rules outside it rather than
 * flattening what it cannot draw. The recurrence picker drew the same line
 * against RRULE, for the same reason: a field that takes the whole grammar is
 * a field nobody can fill in without reading a spec.
 *
 * Everything it offers comes from `/workflow-vocabulary`, so the form cannot
 * compose a trigger, comparison or action this build does not implement. The
 * composed rule is SHOWN as the JSON that will be stored: a picker that hides
 * its output is a black box with a friendly face.
 *
 * Operators are listed by the vocabulary and phrased here. A phrase this file
 * does not have falls back to the operator key — the same rule `describe.ts`
 * follows, because a wrong phrase is believed and never checked again.
 */
const OPERATOR_PHRASES: Record<string, string> = {
  eq: "is",
  neq: "is not",
  in: "is one of",
  not_in: "is none of",
  gt: "is more than",
  gte: "is at least",
  lt: "is less than",
  lte: "is at most",
  contains: "contains",
  is_null: "is empty",
  is_not_null: "is set",
  changed_to: "changes to",
  changed_from: "changes from",
};

/** Operators that take no value, and the ones that take several. */
const VALUELESS = ["is_null", "is_not_null"];
const MULTI = ["in", "not_in"];

type ActionDraft = {
  type: string;
  to: string[];
  notification_type: string;
  message: string;
  levels: number;
  reason: string;
};

export function RuleForm({ vocabulary, rule }: { vocabulary: Vocabulary; rule?: Rule }) {
  const router = useRouter();
  const [busy, startAction] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const [name, setName] = useState(rule?.name ?? "");
  const [description, setDescription] = useState(rule?.description ?? "");
  const [trigger, setTrigger] = useState(rule?.trigger ?? vocabulary.triggers[0]);
  const [rows, setRows] = useState<Leaf[]>(() => (rule ? (leaves(rule.conditions) ?? []) : []));
  const [actions, setActions] = useState<ActionDraft[]>(() =>
    (rule?.actions ?? []).map(toDraft).concat(rule ? [] : [emptyAction(vocabulary)]),
  );

  const buildable = vocabulary.actions.filter((type) =>
    (BUILDABLE_ACTIONS as readonly string[]).includes(type),
  );

  // Only the facts this trigger actually supplies. A condition on
  // `to_state_key` under "work is created" is not refused by the engine — it is
  // simply never true, which is the least debuggable outcome there is.
  const fields = Object.entries(vocabulary.fields).filter(([, field]) =>
    field.triggers.includes(trigger),
  );

  const body: RuleInput = {
    name,
    description,
    trigger,
    conditions: rows.length === 0 ? {} : { all: rows.map(toLeaf(vocabulary)) },
    actions: actions.map(fromDraft),
  };

  return (
    <form
      className="max-w-2xl space-y-5"
      onSubmit={(event) => {
        event.preventDefault();

        startAction(async () => {
          const result = rule ? await saveRule(rule.id, body) : await createRule(body);

          setError(result.error);

          if (result.error === null) router.push("/settings/rules");
        });
      }}
    >
      {error && (
        <p role="alert" className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md">
          {error}
        </p>
      )}

      <Field id="name" label="Name" hint="What an administrator will look for in the list.">
        <input
          id="name"
          className={INPUT}
          value={name}
          maxLength={200}
          required
          onChange={(event) => setName(event.target.value)}
        />
      </Field>

      <Field
        id="description"
        label="Description"
        hint="What it does, in one sentence, for whoever reads it in a year."
      >
        <input
          id="description"
          className={INPUT}
          value={description}
          maxLength={1000}
          onChange={(event) => setDescription(event.target.value)}
        />
      </Field>

      <Field id="trigger" label="Runs when" hint="The moment the engine considers this rule.">
        <select
          id="trigger"
          className={INPUT}
          value={trigger}
          onChange={(event) => {
            setTrigger(event.target.value);
            // Conditions are dropped with the trigger they were written
            // against: keeping a condition on a fact the new trigger does not
            // supply would produce a rule that silently never matches.
            setRows([]);
          }}
        >
          {vocabulary.triggers.map((value) => (
            <option key={value} value={value}>
              {describeTrigger(value)}
            </option>
          ))}
        </select>
      </Field>

      <section aria-labelledby="conditions-heading" className="space-y-2">
        <h2 id="conditions-heading" className="text-body-sm font-medium text-n-700">
          Only if — all of these hold
        </h2>

        {rows.length === 0 && (
          <p className="text-caption text-n-500">
            No conditions: the rule acts every time it is triggered.
          </p>
        )}

        {rows.map((row, index) => {
          const field = vocabulary.fields[row.field];

          return (
            <div key={index} className="flex flex-wrap items-center gap-2">
              <select
                aria-label={`Condition ${index + 1} field`}
                className={`${INPUT} w-auto`}
                value={row.field}
                onChange={(event) => update(setRows, index, { field: event.target.value })}
              >
                {fields.map(([key]) => (
                  <option key={key} value={key}>
                    {key}
                  </option>
                ))}
              </select>

              <select
                aria-label={`Condition ${index + 1} comparison`}
                className={`${INPUT} w-auto`}
                value={row.op}
                onChange={(event) => update(setRows, index, { op: event.target.value })}
              >
                {vocabulary.operators.map((op) => (
                  <option key={op} value={op}>
                    {OPERATOR_PHRASES[op] ?? op}
                  </option>
                ))}
              </select>

              {!VALUELESS.includes(row.op) &&
                (field?.values && !MULTI.includes(row.op) ? (
                  <select
                    aria-label={`Condition ${index + 1} value`}
                    className={`${INPUT} w-auto`}
                    value={String(row.value ?? "")}
                    onChange={(event) => update(setRows, index, { value: event.target.value })}
                  >
                    <option value="">Choose…</option>
                    {field.values.map((value) => (
                      <option key={value} value={value}>
                        {value}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    aria-label={`Condition ${index + 1} value`}
                    className={`${INPUT} w-auto`}
                    value={valueText(row.value)}
                    placeholder={MULTI.includes(row.op) ? "high, urgent" : ""}
                    onChange={(event) => update(setRows, index, { value: event.target.value })}
                  />
                ))}

              <Button
                variant="ghost"
                size="sm"
                type="button"
                onClick={() => setRows((current) => current.filter((_, at) => at !== index))}
              >
                Remove
              </Button>
            </div>
          );
        })}

        <Button
          variant="secondary"
          size="sm"
          type="button"
          disabled={fields.length === 0}
          onClick={() =>
            setRows((current) => [
              ...current,
              { field: fields[0]?.[0] ?? "", op: "eq", value: "" },
            ])
          }
        >
          Add a condition
        </Button>
      </section>

      <section aria-labelledby="actions-heading" className="space-y-3">
        <h2 id="actions-heading" className="text-body-sm font-medium text-n-700">
          Then
        </h2>

        {actions.map((action, index) => (
          <div key={index} className="space-y-2 border border-n-200 p-3 rounded-md">
            <div className="flex items-center gap-2">
              <select
                aria-label={`Action ${index + 1}`}
                className={`${INPUT} w-auto`}
                value={action.type}
                onChange={(event) => update(setActions, index, { type: event.target.value })}
              >
                {buildable.map((type) => (
                  <option key={type} value={type}>
                    {type === "notify" ? "Notify" : "Escalate"}
                  </option>
                ))}
              </select>

              {actions.length > 1 && (
                <Button
                  variant="ghost"
                  size="sm"
                  type="button"
                  onClick={() => setActions((current) => current.filter((_, at) => at !== index))}
                >
                  Remove
                </Button>
              )}
            </div>

            {action.type === "notify" ? (
              <>
                <fieldset className="flex flex-wrap gap-3">
                  <legend className="mb-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                    Who
                  </legend>
                  {/* Roles relative to the work, never a person: a rule that
                      hardcodes somebody breaks the day they change teams. */}
                  {vocabulary.audiences.map((audience) => (
                    <label key={audience} className="flex items-center gap-1.5 text-body-sm">
                      <input
                        type="checkbox"
                        checked={action.to.includes(audience)}
                        onChange={(event) =>
                          update(setActions, index, {
                            to: event.target.checked
                              ? [...action.to, audience]
                              : action.to.filter((name) => name !== audience),
                          })
                        }
                      />
                      {audience.replace("_", " ")}
                    </label>
                  ))}
                </fieldset>

                <Field id={`message-${index}`} label="Message" hint="Optional, shown with the notification.">
                  <input
                    id={`message-${index}`}
                    className={INPUT}
                    value={action.message}
                    onChange={(event) => update(setActions, index, { message: event.target.value })}
                  />
                </Field>
              </>
            ) : (
              <>
                <Field
                  id={`levels-${index}`}
                  label="How far up"
                  hint="Levels of the reporting line to notify. Escalation notifies; it never reassigns."
                >
                  <input
                    id={`levels-${index}`}
                    type="number"
                    min={1}
                    max={3}
                    className={INPUT}
                    value={action.levels}
                    onChange={(event) =>
                      update(setActions, index, { levels: Number(event.target.value) })
                    }
                  />
                </Field>

                <Field id={`reason-${index}`} label="Reason" hint="What the manager is being told.">
                  <input
                    id={`reason-${index}`}
                    className={INPUT}
                    value={action.reason}
                    onChange={(event) => update(setActions, index, { reason: event.target.value })}
                  />
                </Field>
              </>
            )}
          </div>
        ))}

        <Button
          variant="secondary"
          size="sm"
          type="button"
          onClick={() => setActions((current) => [...current, emptyAction(vocabulary)])}
        >
          Add an action
        </Button>
      </section>

      {/* What will be stored, as it will be stored. The recurrence picker
          prints its RRULE for the same reason: the person saving this is the
          only one who can notice it says something they did not mean. */}
      <section aria-labelledby="preview-heading" className="space-y-1">
        <h2 id="preview-heading" className="text-body-sm font-medium text-n-700">
          What gets stored
        </h2>
        <pre className="overflow-x-auto whitespace-pre-wrap break-words border border-n-100 bg-n-50 p-3 font-mono text-micro text-n-700 rounded-md">
          {JSON.stringify({ conditions: body.conditions, actions: body.actions }, null, 2)}
        </pre>
      </section>

      <div className="flex items-center gap-2">
        <Button type="submit" variant="primary" disabled={busy || name === ""}>
          {busy ? "Saving…" : rule ? "Save the rule" : "Create the rule"}
        </Button>
        <Button type="button" variant="ghost" onClick={() => router.push("/settings/rules")}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function update<T>(
  setter: React.Dispatch<React.SetStateAction<T[]>>,
  index: number,
  patch: Partial<T>,
): void {
  setter((current) => current.map((item, at) => (at === index ? { ...item, ...patch } : item)));
}

function emptyAction(vocabulary: Vocabulary): ActionDraft {
  return {
    type: vocabulary.actions.includes("notify") ? "notify" : "escalate",
    to: ["assignee"],
    notification_type: "workflow.rule",
    message: "",
    levels: 1,
    reason: "",
  };
}

function toDraft(action: { type: string; with?: Record<string, unknown> }): ActionDraft {
  const config = action.with ?? {};

  return {
    type: action.type,
    to: Array.isArray(config.to) ? config.to.map(String) : ["assignee"],
    notification_type: String(config.notification_type ?? "workflow.rule"),
    message: String(config.message ?? ""),
    levels: Number(config.levels ?? 1),
    reason: String(config.reason ?? ""),
  };
}

function fromDraft(draft: ActionDraft): { type: string; with: Record<string, unknown> } {
  if (draft.type === "notify") {
    const config: Record<string, unknown> = {
      to: draft.to,
      notification_type: draft.notification_type,
    };

    // Blank fields are not sent: `""` is not "no value" to a validator, and an
    // empty message would overwrite the handler's own default.
    if (draft.message !== "") config.message = draft.message;

    return { type: draft.type, with: config };
  }

  const config: Record<string, unknown> = { levels: draft.levels };

  if (draft.reason !== "") config.reason = draft.reason;

  return { type: draft.type, with: config };
}

/** The value as the engine will read it. */
function toLeaf(vocabulary: Vocabulary) {
  return (row: Leaf): Record<string, unknown> => {
    if (VALUELESS.includes(row.op)) {
      return { field: row.field, op: row.op };
    }

    const text = valueText(row.value);

    if (MULTI.includes(row.op)) {
      return {
        field: row.field,
        op: row.op,
        value: text
          .split(",")
          .map((part) => part.trim())
          .filter((part) => part !== ""),
      };
    }

    // Numbers are sent as numbers. The evaluator compares most operators as
    // strings, but `gt`/`lte` and their kin read NAN from anything that is not
    // numeric — and a comparison that is always false is the silent failure
    // this whole form exists to prevent.
    return {
      field: row.field,
      op: row.op,
      value: vocabulary.fields[row.field]?.type === "number" ? Number(text) : text,
    };
  };
}

function valueText(value: unknown): string {
  return Array.isArray(value) ? value.join(", ") : value === null || value === undefined ? "" : String(value);
}
