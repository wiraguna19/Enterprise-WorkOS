"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import {
  declareField,
  deleteField,
  reorderFields,
  saveField,
  setFieldLive,
} from "./actions";
import { TYPE_DESCRIPTIONS, type CustomField, type FieldScope, type FieldType } from "./types";

/**
 * Declaring, editing and retiring the fields of one scope (ADR 0038).
 *
 * The shape of this screen follows one decision: **a key is frozen and a label
 * is not**, because `filter[cf_<key>]` is published API grammar. So the form
 * offers the key once, at declaration, with the explanation attached — and the
 * row afterwards shows it as text beside the filter it produces, never as an
 * input. A control the API will refuse is the dead control this product keeps
 * finding; not rendering it is cheaper than explaining it.
 *
 * Retiring and deleting are separate controls with separate words, for the same
 * reason the recurrence screen says "Stop" and not "Delete": retiring keeps
 * every answer on every record and deleting destroys them. One button named for
 * the gentler of the two would eventually perform the other.
 */
export function FieldEditor({
  scope,
  fields,
  types,
}: {
  scope: FieldScope;
  fields: CustomField[];
  types: FieldType[];
}) {
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [saving, start] = useTransition();

  const live = fields.filter((field) => field.live);

  function run(action: () => Promise<{ error: string | null }>): void {
    start(async () => {
      const result = await action();
      setError(result.error);

      if (result.error === null) setEditing(null);
    });
  }

  function move(field: CustomField, by: -1 | 1): void {
    const index = live.findIndex((candidate) => candidate.id === field.id);
    const target = index + by;

    if (index < 0 || target < 0 || target >= live.length) return;

    const order = live.map((candidate) => candidate.id);
    const [moved] = order.splice(index, 1);
    order.splice(target, 0, moved);

    run(() => reorderFields(scope, order));
  }

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <Panel
        id="fields"
        title="Fields"
        description={
          fields.length === 0
            ? "None yet. A field you declare here appears on every record of this kind."
            : `${live.length} in use${fields.length > live.length ? `, ${fields.length - live.length} retired` : ""}.`
        }
        bleed
      >
        {fields.length > 0 && (
          <DataTable caption="Custom fields, in the order they appear on the form">
            <THead>
              <Tr>
                <Th>Label</Th>
                <Th>Filter</Th>
                <Th>Type</Th>
                <Th>Required</Th>
                <Th align="right">Order</Th>
                <Th align="right">Actions</Th>
              </Tr>
            </THead>
            <TBody>
              {fields.map((field) => (
                <Tr key={field.id}>
                  <Td>
                    <span className="font-medium">{field.label}</span>
                    {!field.live && (
                      <span className="ml-2">
                        <Badge tone="neutral" icon="minus">
                          retired
                        </Badge>
                      </span>
                    )}
                  </Td>
                  {/* The filter key, not the bare key: it is what somebody
                      would paste into a URL, and showing the raw key would
                      leave them to guess the prefix. */}
                  <Td muted>
                    <code className="font-mono text-micro">{field.filter_key}</code>
                  </Td>
                  <Td muted>{field.type}</Td>
                  <Td muted>{field.required ? "Yes" : "—"}</Td>
                  <Td align="right">
                    {field.live && (
                      <span className="inline-flex gap-1">
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={saving || live[0]?.id === field.id}
                          onClick={() => move(field, -1)}
                          aria-label={`Move ${field.label} up`}
                        >
                          ↑
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={saving || live.at(-1)?.id === field.id}
                          onClick={() => move(field, 1)}
                          aria-label={`Move ${field.label} down`}
                        >
                          ↓
                        </Button>
                      </span>
                    )}
                  </Td>
                  <Td align="right">
                    <span className="inline-flex flex-wrap justify-end gap-1">
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={saving}
                        onClick={() => setEditing(editing === field.id ? null : field.id)}
                      >
                        {editing === field.id ? "Close" : "Edit"}
                      </Button>

                      {field.live ? (
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={saving}
                          onClick={() => run(() => setFieldLive(scope, field.id, false))}
                        >
                          Retire
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="affirmative"
                          disabled={saving}
                          onClick={() => run(() => setFieldLive(scope, field.id, true))}
                        >
                          Bring back
                        </Button>
                      )}

                      <DeleteField
                        label={field.label}
                        disabled={saving}
                        onConfirm={() => run(() => deleteField(scope, field.id))}
                      />
                    </span>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </DataTable>
        )}
      </Panel>

      {fields
        .filter((field) => field.id === editing)
        .map((field) => (
          <EditOne
            key={field.id}
            field={field}
            saving={saving}
            onSave={(input) => run(() => saveField(scope, field.id, input))}
          />
        ))}

      <DeclareOne
        scope={scope}
        types={types}
        saving={saving}
        onDeclare={(input) => run(() => declareField(scope, input))}
      />
    </div>
  );
}

/**
 * Deleting asks first, in place.
 *
 * Not a browser `confirm()`: it blocks the page, cannot be styled, and reads to
 * a screen reader as a message from the browser rather than from the product.
 * The second click is the confirmation, and the button says what will happen to
 * the answers — which is the fact that decides it.
 */
function DeleteField({
  label,
  disabled,
  onConfirm,
}: {
  label: string;
  disabled: boolean;
  onConfirm: () => void;
}) {
  const [armed, setArmed] = useState(false);

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        Delete
      </Button>
    );
  }

  return (
    <span className="inline-flex items-center gap-1">
      <Button
        size="sm"
        variant="danger"
        disabled={disabled}
        onClick={() => {
          setArmed(false);
          onConfirm();
        }}
      >
        Delete {label} and its answers
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        Cancel
      </Button>
    </span>
  );
}

function EditOne({
  field,
  saving,
  onSave,
}: {
  field: CustomField;
  saving: boolean;
  onSave: (input: { label: string; required: boolean; options?: string[] }) => void;
}) {
  const [label, setLabel] = useState(field.label);
  const [required, setRequired] = useState(field.required);
  const [options, setOptions] = useState(field.options.join("\n"));

  return (
    <Panel
      id={`edit-${field.id}`}
      title={`Edit ${field.label}`}
      description={
        <>
          The key <code className="font-mono">{field.key}</code> cannot change — it is what{" "}
          <code className="font-mono">{field.filter_key}</code> filters on, and every saved link
          uses it. Neither can the type.
        </>
      }
      footer={
        <Button
          variant="primary"
          size="sm"
          disabled={saving || label.trim() === ""}
          onClick={() =>
            onSave({
              label: label.trim(),
              required,
              ...(field.type === "select" ? { options: splitOptions(options) } : {}),
            })
          }
        >
          {saving ? "Saving…" : "Save"}
        </Button>
      }
    >
      <div className="space-y-3">
        <Field id={`label-${field.id}`} label="Label">
          <input
            id={`label-${field.id}`}
            value={label}
            onChange={(event) => setLabel(event.target.value)}
            className={INPUT}
          />
        </Field>

        {field.type === "select" && (
          <Field
            id={`options-${field.id}`}
            label="Options"
            hint="One per line. Removing one does not remove it from records that already chose it."
          >
            <textarea
              id={`options-${field.id}`}
              rows={5}
              value={options}
              onChange={(event) => setOptions(event.target.value)}
              className={`${INPUT} resize-y`}
            />
          </Field>
        )}

        <RequiredToggle
          id={`required-${field.id}`}
          checked={required}
          onChange={setRequired}
        />
      </div>
    </Panel>
  );
}

function DeclareOne({
  scope,
  types,
  saving,
  onDeclare,
}: {
  scope: FieldScope;
  types: FieldType[];
  saving: boolean;
  onDeclare: (input: {
    key: string;
    label: string;
    type: FieldType;
    required: boolean;
    options: string[];
  }) => void;
}) {
  const [label, setLabel] = useState("");
  const [key, setKey] = useState("");
  const [type, setType] = useState<FieldType>(types[0] ?? "text");
  const [required, setRequired] = useState(false);
  const [options, setOptions] = useState("");

  // Suggested from the label until the person edits it themselves, and then
  // left alone. A key that keeps rewriting itself under somebody typing is
  // worse than no suggestion — and this one is frozen the moment it is saved,
  // so it has to be theirs.
  const [keyTouched, setKeyTouched] = useState(false);
  const suggested = keyTouched ? key : slug(label);

  const ready = label.trim() !== "" && suggested !== "" && (type !== "select" || splitOptions(options).length > 0);

  return (
    <Panel
      id="declare"
      title="Declare a field"
      description={`It appears on every ${scope === "work_item" ? "work item" : "project"} in this organization.`}
      footer={
        <Button
          variant="primary"
          size="sm"
          disabled={saving || !ready}
          onClick={() => {
            onDeclare({
              key: suggested,
              label: label.trim(),
              type,
              required,
              options: splitOptions(options),
            });
            setLabel("");
            setKey("");
            setKeyTouched(false);
            setOptions("");
            setRequired(false);
          }}
        >
          {saving ? "Declaring…" : "Declare"}
        </Button>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id="new-label" label="Label" hint="What people see on the form.">
          <input
            id="new-label"
            value={label}
            onChange={(event) => setLabel(event.target.value)}
            placeholder="Client"
            className={INPUT}
          />
        </Field>

        <Field
          id="new-key"
          label="Key"
          hint={`Frozen once declared. Filters as cf_${suggested || "…"}.`}
        >
          <input
            id="new-key"
            value={suggested}
            onChange={(event) => {
              setKeyTouched(true);
              setKey(slug(event.target.value));
            }}
            placeholder="client"
            className={`${INPUT} font-mono`}
          />
        </Field>

        <Field id="new-type" label="Type" hint={TYPE_DESCRIPTIONS[type]}>
          <select
            id="new-type"
            value={type}
            onChange={(event) => setType(event.target.value as FieldType)}
            className={INPUT}
          >
            {types.map((candidate) => (
              <option key={candidate} value={candidate}>
                {candidate}
              </option>
            ))}
          </select>
        </Field>

        <div className="flex items-end">
          <RequiredToggle id="new-required" checked={required} onChange={setRequired} />
        </div>

        {type === "select" && (
          <div className="sm:col-span-2">
            <Field
              id="new-options"
              label="Options"
              hint="One per line. A select with no options is a field nobody can answer, so it is refused."
            >
              <textarea
                id="new-options"
                rows={4}
                value={options}
                onChange={(event) => setOptions(event.target.value)}
                placeholder={"Acme\nGlobex"}
                className={`${INPUT} resize-y`}
              />
            </Field>
          </div>
        )}
      </div>
    </Panel>
  );
}

/**
 * "Required" says what it costs, because it cannot be retroactive.
 *
 * Turning it on does not invalidate the records that already exist — the API
 * checks it when a record is saved, not when the switch is flipped — and
 * somebody flipping it deserves to know that before they go looking for the
 * items it "should" have caught.
 */
function RequiredToggle({
  id,
  checked,
  onChange,
}: {
  id: string;
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <label htmlFor={id} className="flex items-start gap-2 text-body-sm text-n-700">
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 size-4 rounded-sm border-n-300"
      />
      <span>
        Required
        <span className="block text-caption text-n-500">
          Asked of records saved from now on. Existing ones are left alone.
        </span>
      </span>
    </label>
  );
}

/** A label as a key: lower-case, underscores, no leading digit. */
function slug(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^[^a-z]+/, "")
    .replace(/_+$/, "")
    .slice(0, 40);
}

/** One option per line, blank lines dropped. */
function splitOptions(value: string): string[] {
  return value
    .split("\n")
    .map((option) => option.trim())
    .filter((option) => option !== "");
}
