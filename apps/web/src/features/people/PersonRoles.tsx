"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { grantRole, revokeRole } from "./roles";

/**
 * What this person may do, and where (docs/06 §2, ADR 0016, ADR 0024).
 *
 * Organization-wide roles are read-only here: making somebody an administrator
 * of everything is a different act from putting them in charge of one team, and
 * a control that did both would let a slip of a dropdown do the larger one.
 * They are badges in the panel header — a fact about the person, not a list to
 * work through.
 *
 * Scoped grants are what this screen writes, and they are the mechanism behind
 * "the lead of Frontend may manage Frontend" — a row with a grantor, a
 * timestamp and an activity record, rather than an `if (lead)` nobody outside
 * the codebase can see. They are a TABLE now: role, scope, and the action, in
 * three columns instead of one sentence per line.
 *
 * The grant form lives in the panel footer, on its own surface. A form that
 * shared a background with the list read as another row of it.
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
  /** False for a viewer, for an administrator looking at themselves, and for
   *  somebody erased (ADR 0022). */
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
    <Panel
      id="roles"
      title="Roles"
      description="Authority here comes from a grant, never from leading a team or heading a department."
      actions={
        organizationWide.length === 0 ? (
          <span className="text-body-sm text-n-500">None across the organization</span>
        ) : (
          organizationWide.map((entry) => (
            <Badge key={entry.key} tone="info">
              {entry.name} · everywhere
            </Badge>
          ))
        )
      }
      bleed
      footer={
        mayManage ? (
          <form
            className="flex flex-wrap items-end gap-3"
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
            <Field
              id="grant-role"
              label="Give them"
              hint="Everything that role can do — here only."
            >
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
                  // sending it would be refused as a scope that does not
                  // exist — correctly, and confusingly.
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

            <Button type="submit" variant="affirmative" size="sm" disabled={busy || scopeId === ""}>
              Grant
            </Button>
          </form>
        ) : undefined
      }
    >
      {error && (
        <p
          role="alert"
          className="border-b border-s-danger/40 bg-s-danger/5 px-4 py-2 text-body-sm text-s-danger"
        >
          {error}
        </p>
      )}

      {scoped.length === 0 ? (
        <p className="px-4 py-3 text-body-sm text-n-500">
          No grants on any one project, team or department.
        </p>
      ) : (
        <DataTable caption="Roles granted on one project, team or department">
          <THead>
            <Tr>
              <Th>Role</Th>
              <Th>On</Th>
              {mayManage && <Th width="w-24" align="right">Action</Th>}
            </Tr>
          </THead>
          <TBody>
            {scoped.map((grant) => (
              <Tr key={grant.id}>
                <Td>{grant.name}</Td>
                <Td muted>
                  on {grant.scope_type} {grant.scope_name ?? grant.scope_id}
                </Td>
                {mayManage && (
                  <Td align="right">
                    <Button
                      variant="destructive"
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
                  </Td>
                )}
              </Tr>
            ))}
          </TBody>
        </DataTable>
      )}
    </Panel>
  );
}
