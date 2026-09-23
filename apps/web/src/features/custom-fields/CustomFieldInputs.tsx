"use client";

import { Field, INPUT } from "@/components/ui/Field";
import type { CustomFieldAnswer } from "./types";

/**
 * The controls for an organization's own fields, on a record's form (ADR 0038).
 *
 * One component rather than a branch inside each form: the work item form and
 * the project form will render the same four types, and two copies of a type
 * switch is how a `select` becomes a text box on one screen.
 *
 * **A retired field is printed, never offered.** It still holds an answer on
 * this record, and hiding it would make the value disappear from the only place
 * anybody could see it; offering a control would write to a field the
 * administrator deliberately withdrew, which the API refuses anyway.
 */
export function CustomFieldInputs({
  fields,
  values,
  onChange,
  idPrefix,
}: {
  fields: CustomFieldAnswer[];
  /** key → current value, "" meaning unanswered. */
  values: Record<string, string>;
  onChange: (key: string, value: string) => void;
  idPrefix: string;
}) {
  if (fields.length === 0) return null;

  return (
    <div className="space-y-3">
      {fields.map((field) => {
        const id = `${idPrefix}-${field.key}`;
        const value = values[field.key] ?? "";

        if (!field.live) {
          return (
            <Field
              key={field.key}
              id={id}
              label={field.label}
              hint="This field has been retired. Its answer is kept, and cannot be changed."
            >
              <p id={id} className="text-body-sm text-n-700">
                {value === "" ? "—" : value}
              </p>
            </Field>
          );
        }

        return (
          <Field
            key={field.key}
            id={id}
            label={field.required ? `${field.label} *` : field.label}
            hint={field.required ? "Required. Clearing this is refused." : undefined}
          >
            {field.type === "select" ? (
              <select
                id={id}
                value={value}
                onChange={(event) => onChange(field.key, event.target.value)}
                className={INPUT}
              >
                {/* An explicit empty choice, because "no answer" is a real
                    state and a select with no way back to it is a one-way
                    door. The API refuses it for a required field and says so,
                    which is better than a control that cannot express it. */}
                <option value="">—</option>
                {field.options.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            ) : (
              <input
                id={id}
                // `number` and `date` get the right keyboard and the right
                // picker; the value still travels as the string the API stores.
                type={field.type === "number" ? "number" : field.type === "date" ? "date" : "text"}
                step={field.type === "number" ? "any" : undefined}
                value={value}
                onChange={(event) => onChange(field.key, event.target.value)}
                maxLength={field.type === "text" ? 500 : undefined}
                className={INPUT}
              />
            )}
          </Field>
        );
      })}
    </div>
  );
}

/** The answers as a form's initial state: key → value, "" for unanswered. */
export function initialValues(fields: CustomFieldAnswer[]): Record<string, string> {
  return Object.fromEntries(fields.map((field) => [field.key, field.value ?? ""]));
}

/**
 * Only what changed, as the API takes it.
 *
 * `null` for a cleared answer, never `""`: an empty string is not "no value" to
 * a validator, and this codebase has shipped that bug before.
 */
export function changedValues(
  fields: CustomFieldAnswer[],
  before: Record<string, string>,
  now: Record<string, string>,
): Record<string, string | null> {
  const changes: Record<string, string | null> = {};

  for (const field of fields) {
    if (!field.live) continue;

    const was = before[field.key] ?? "";
    const is = now[field.key] ?? "";

    if (was !== is) changes[field.key] = is === "" ? null : is;
  }

  return changes;
}
