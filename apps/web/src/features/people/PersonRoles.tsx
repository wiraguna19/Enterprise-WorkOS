"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { grantRole, revokeRole } from "./roles";

/**
 * What this person may do, and where (docs/06 §2, ADR 0016).
 *
 * Two lists, deliberately kept apart. An organization-wide role is read-only
 * here: making somebody an administrator of everything is a different act from
 * putting them in charge of one team, and a control that did both would let a
 * slip of a dropdown do the larger one.
 *
 * Scoped grants are what this screen writes, and they are the mechanism behind
 * "the lead of Frontend may manage Frontend" — a row with a grantor, a
 * timestamp and an activity record, rather than an `if (lead)` nobody outside
 * the codebase can see.
 */
export type Grant = {
  id: string;
  key: string;
  name: string;
  scope_type: string;
  scope_id: string;
  scope_name: string | null;
};

export type Scope = { id: string; name: string };

export function PersonRoles({
  membershipId,
  organizationWide,
  scoped,
  mayManage,
  roles,
  scopes,
}: {
  membershipId: string;
  organizationWide: Array<{ key: string; name: string }>;
  scoped: Grant[];
  /** False for a viewer, and for an administrator looking at themselves. */
  mayManage: boolean;
  roles: Array<{ key: string; name: string }>;
  scopes: { team: Scope[]; department: Scope[]; project: Scope[] };
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const [role, setRole] = useState(roles[0]?.key ?? "");
  const [scopeType, setScopeType] = useState<keyof typeof scopes>("team");
  const [scopeId, setScopeId] = useState("");

  const options = scopes[scopeType];

  return (
    <section aria-labelledby="roles-heading" className="space-y-3">
      <h2 id="roles-heading" className="text-h2 font-semibold text-n-900">
        Roles
      </h2>

      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <div>
        <h3 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          Across the organization
        </h3>
        <p className="mt-1 text-body-sm text-n-700">
          {organizationWide.length === 0
            ? "None."
            : organizationWide.map((entry) => entry.name).join(", ")}
        </p>
      </div>

      <div>
        <h3 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          On one thing
        </h3>

        {scoped.length === 0 ? (
          <p className="mt-1 text-body-sm text-n-500">
            No grants. Authority here comes from a grant, never from leading a team or heading a
            department.
          </p>
        ) : (
          <ul className="mt-1 divide-y divide-n-100 border-y border-n-100">
            {scoped.map((grant) => (
              <li key={grant.id} className="flex flex-wrap items-center gap-x-3 py-2 text-body-sm">
                <span className="min-w-0 flex-1">
                  <span className="text-n-900">{grant.name}</span>{" "}
                  <span className="text-n-500">
                    on {grant.scope_type} {grant.scope_name ?? grant.scope_id}
                  </span>
                </span>

                {mayManage && (
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    onClick={() =>
                      startAction(async () => {
                        const result = await revokeRole(membershipId, grant.id);

                        setError(result.error);
                      })
                    }
                  >
                    Revoke
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      {mayManage && (
        <form
          className="flex flex-wrap items-end gap-3 border border-n-200 p-3 rounded-md"
          onSubmit={(event) => {
            event.preventDefault();

            startAction(async () => {
              const result = await grantRole(membershipId, {
                role,
                scope_type: scopeType,
                scope_id: scopeId,
              });

              setError(result.error);

              if (result.error === null) setScopeId("");
            });
          }}
        >
          <Field id="grant-role" label="Give them" hint="Everything that role can do — here only.">
            <select
              id="grant-role"
              className={INPUT}
              value={role}
              onChange={(event) => setRole(event.target.value)}
            >
              {roles.map((entry) => (
                <option key={entry.key} value={entry.key}>
                  {entry.name}
                </option>
              ))}
            </select>
          </Field>

          <Field id="grant-scope-type" label="On a">
            <select
              id="grant-scope-type"
              className={INPUT}
              value={scopeType}
              onChange={(event) => {
                setScopeType(event.target.value as keyof typeof scopes);
                // The previous id belongs to the previous kind of thing, and
                // sending it would be refused as a scope that does not exist —
                // correctly, and confusingly.
                setScopeId("");
              }}
            >
              <option value="team">team</option>
              <option value="department">department</option>
              <option value="project">project</option>
            </select>
          </Field>

          <Field id="grant-scope" label="Which one">
            <select
              id="grant-scope"
              className={INPUT}
              value={scopeId}
              required
              onChange={(event) => setScopeId(event.target.value)}
            >
              <option value="">Choose…</option>
              {options.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.name}
                </option>
              ))}
            </select>
          </Field>

          <Button type="submit" variant="secondary" size="sm" disabled={busy || scopeId === ""}>
            Grant
          </Button>
        </form>
      )}
    </section>
  );
}
