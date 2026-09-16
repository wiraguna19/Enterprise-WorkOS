"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { createRole, deleteRole, saveRole } from "./actions";

/**
 * A role, and what is in it (ADR 0018).
 *
 * Permissions are grouped by the resource they name, because that is how
 * somebody thinks about them — "what may they do to departments" — and a flat
 * list of sixty checkboxes is a list nobody reads to the end.
 *
 * The four roles this product ships with are shown and not editable. The form
 * says why rather than rendering disabled boxes: a control that cannot be used
 * is a question the reader has to answer for themselves.
 */
export type Role = {
  key: string;
  name: string;
  description: string;
  is_system: boolean;
  permissions: string[];
  held_by: number;
};

export type Permission = {
  key: string;
  resource: string;
  action: string;
  description: string;
};

export function RoleEditor({
  roles,
  permissions,
  mayManage,
}: {
  roles: Role[];
  permissions: Permission[];
  mayManage: boolean;
}) {
  const [editing, setEditing] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const byResource = new Map<string, Permission[]>();

  for (const permission of permissions) {
    byResource.set(permission.resource, [
      ...(byResource.get(permission.resource) ?? []),
      permission,
    ]);
  }

  return (
    <div className="space-y-5">
      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <ul className="space-y-3">
        {roles.map((role) => (
          <li key={role.key} className="border border-n-200 p-4 rounded-md">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <h2 className="font-medium text-n-900">{role.name}</h2>
              <span className="font-mono text-micro text-n-500">{role.key}</span>
            </div>

            <p className="mt-0.5 max-w-prose text-caption text-n-500">
              {role.description || "No description."}
            </p>

            <p className="mt-2 text-body-sm text-n-700">
              {role.permissions.length} permissions ·{" "}
              {role.held_by === 1 ? "1 person holds it" : `${role.held_by} people hold it`}
            </p>

            {editing === role.key ? (
              <PermissionForm
                byResource={byResource}
                initial={role.permissions}
                busy={busy}
                submitLabel="Save the role"
                onCancel={() => setEditing(null)}
                onSubmit={(chosen) =>
                  startAction(async () => {
                    const result = await saveRole(role.key, { permissions: chosen });

                    setError(result.error);

                    if (result.error === null) setEditing(null);
                  })
                }
              />
            ) : (
              mayManage && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  {role.is_system ? (
                    // Shown rather than hidden, and explained rather than
                    // disabled: every permission test, the seed and docs/06
                    // assume these four mean what they say.
                    <p className="text-caption text-n-500">
                      One of the roles this product ships with — it cannot be changed or removed.
                    </p>
                  ) : (
                    <>
                      <Button variant="secondary" size="sm" onClick={() => setEditing(role.key)}>
                        Edit permissions
                      </Button>

                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                          startAction(async () => {
                            const result = await deleteRole(role.key);

                            setError(result.error);
                          })
                        }
                      >
                        Delete
                      </Button>
                    </>
                  )}
                </div>
              )
            )}
          </li>
        ))}
      </ul>

      {mayManage &&
        (creating ? (
          <NewRole
            byResource={byResource}
            busy={busy}
            onCancel={() => setCreating(false)}
            onSubmit={(input) =>
              startAction(async () => {
                const result = await createRole(input);

                setError(result.error);

                if (result.error === null) setCreating(false);
              })
            }
          />
        ) : (
          <Button variant="primary" onClick={() => setCreating(true)}>
            New role
          </Button>
        ))}
    </div>
  );
}

function NewRole({
  byResource,
  busy,
  onCancel,
  onSubmit,
}: {
  byResource: Map<string, Permission[]>;
  busy: boolean;
  onCancel: () => void;
  onSubmit: (input: { key: string; name: string; description: string; permissions: string[] }) => void;
}) {
  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");

  return (
    <div className="space-y-4 border border-n-200 p-4 rounded-md">
      <Field id="role-name" label="Name" hint="What an administrator picks from a list.">
        <input
          id="role-name"
          className={INPUT}
          value={name}
          maxLength={120}
          onChange={(event) => setName(event.target.value)}
        />
      </Field>

      <Field id="role-key" label="Key" hint="What a grant names and an audit log records. It cannot be changed later.">
        <input
          id="role-key"
          className={INPUT}
          value={key}
          maxLength={60}
          pattern="[a-z][a-z0-9_]*"
          onChange={(event) => setKey(event.target.value)}
        />
      </Field>

      <Field id="role-description" label="Description" hint="What it is for, for whoever reads it in a year.">
        <input
          id="role-description"
          className={INPUT}
          value={description}
          maxLength={255}
          onChange={(event) => setDescription(event.target.value)}
        />
      </Field>

      <PermissionForm
        byResource={byResource}
        initial={[]}
        busy={busy || key === "" || name === ""}
        submitLabel="Create the role"
        onCancel={onCancel}
        onSubmit={(permissions) => onSubmit({ key, name, description, permissions })}
      />
    </div>
  );
}

/**
 * The checkboxes, grouped by resource.
 *
 * Only what this build implements: the catalogue comes from the API, so a form
 * cannot offer a permission the product does not have — which would write a
 * role that grants nothing and reads as though it grants something.
 */
function PermissionForm({
  byResource,
  initial,
  busy,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  byResource: Map<string, Permission[]>;
  initial: string[];
  busy: boolean;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (permissions: string[]) => void;
}) {
  const [chosen, setChosen] = useState<string[]>(initial);

  const toggle = (key: string, on: boolean) =>
    setChosen((current) => (on ? [...current, key] : current.filter((entry) => entry !== key)));

  return (
    <form
      className="mt-3 space-y-3"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit(chosen);
      }}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {[...byResource.entries()].map(([resource, entries]) => (
          <fieldset key={resource} className="border border-n-100 p-2 rounded-md">
            <legend className="px-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
              {resource}
            </legend>

            <ul className="space-y-0.5">
              {entries.map((permission) => (
                <li key={permission.key}>
                  <label className="flex items-start gap-1.5 text-body-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={chosen.includes(permission.key)}
                      onChange={(event) => toggle(permission.key, event.target.checked)}
                    />
                    <span>
                      <span className="text-n-900">{permission.action}</span>{" "}
                      <span className="text-caption text-n-500">{permission.description}</span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          </fieldset>
        ))}
      </div>

      <div className="flex items-center gap-2">
        <Button type="submit" variant="primary" size="sm" disabled={busy}>
          {submitLabel}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
