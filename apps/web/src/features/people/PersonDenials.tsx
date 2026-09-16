"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { denyPermission, explainPermission, liftDenial, type Explanation } from "./roles";
import type { Scope } from "./PersonRoles";

/**
 * What this person may NOT do, whatever they have been granted (ADR 0020).
 *
 * On the same screen as the grants, directly beneath them, because "what may
 * they do" is one question: an interface that showed the grants and hid the
 * denials would answer it wrongly in the most confident way available — with a
 * list that looks complete.
 *
 * The explainer below is not a nicety. Deny wins over every grant, so a person
 * can hold Manager on a team and still be refused, and without a reader that
 * refusal is a wall with no sign on it: neither they nor the administrator they
 * ask can tell a permission never granted from one taken away, or why.
 */
export type Denial = {
  id: string;
  permission: string;
  scope_type: string | null;
  scope_id: string | null;
  scope_name: string | null;
  reason: string;
};

export function PersonDenials({
  membershipId,
  denials,
  mayManage,
  permissions,
  scopes,
}: {
  membershipId: string;
  denials: Denial[];
  /** False for a viewer, and for an administrator looking at themselves. */
  mayManage: boolean;
  permissions: Array<{ key: string; description: string | null }>;
  scopes: { team: Scope[]; department: Scope[]; project: Scope[] };
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const [permission, setPermission] = useState(permissions[0]?.key ?? "");
  const [where, setWhere] = useState<"everywhere" | keyof typeof scopes>("everywhere");
  const [scopeId, setScopeId] = useState("");
  const [reason, setReason] = useState("");

  const [asked, setAsked] = useState(permissions[0]?.key ?? "");
  const [explanation, setExplanation] = useState<Explanation | null>(null);

  const options = where === "everywhere" ? [] : scopes[where];

  return (
    <section aria-labelledby="denials-heading" className="space-y-3">
      <h2 id="denials-heading" className="text-h2 font-semibold text-n-900">
        Denials
      </h2>

      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      {denials.length === 0 ? (
        <p className="text-body-sm text-n-500">
          Nothing taken away. A denial beats every grant, including one made after it.
        </p>
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {denials.map((denial) => (
            <li key={denial.id} className="flex flex-wrap items-center gap-x-3 py-2 text-body-sm">
              <span className="min-w-0 flex-1">
                <span className="text-n-900">{denial.permission}</span>{" "}
                <span className="text-n-500">
                  {denial.scope_type === null
                    ? "everywhere"
                    : `on ${denial.scope_type} ${denial.scope_name ?? denial.scope_id}`}
                </span>
                <span className="block text-n-500">{denial.reason}</span>
              </span>

              {mayManage && (
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    startAction(async () => {
                      const result = await liftDenial(membershipId, denial.id);

                      setError(result.error);
                    })
                  }
                >
                  Lift
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {mayManage && (
        <form
          className="flex flex-wrap items-end gap-3 border border-n-200 p-3 rounded-md"
          onSubmit={(event) => {
            event.preventDefault();

            startAction(async () => {
              const result = await denyPermission(membershipId, {
                permission,
                scope_type: where === "everywhere" ? null : where,
                scope_id: where === "everywhere" ? null : scopeId,
                reason,
              });

              setError(result.error);

              if (result.error === null) {
                setScopeId("");
                setReason("");
              }
            });
          }}
        >
          <Field
            id="deny-permission"
            label="They may not"
            hint="Beats every role they hold, now or later."
          >
            <select
              id="deny-permission"
              className={INPUT}
              value={permission}
              onChange={(event) => setPermission(event.target.value)}
            >
              {permissions.map((entry) => (
                <option key={entry.key} value={entry.key}>
                  {entry.key}
                </option>
              ))}
            </select>
          </Field>

          <Field id="deny-where" label="Where">
            <select
              id="deny-where"
              className={INPUT}
              value={where}
              onChange={(event) => {
                setWhere(event.target.value as "everywhere" | keyof typeof scopes);
                // The previous id belongs to the previous kind of thing, and
                // sending it would be refused as a scope that does not exist.
                setScopeId("");
              }}
            >
              <option value="everywhere">everywhere</option>
              <option value="team">on one team</option>
              <option value="department">on one department</option>
              <option value="project">on one project</option>
            </select>
          </Field>

          {where !== "everywhere" && (
            <Field id="deny-scope" label="Which one">
              <select
                id="deny-scope"
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
          )}

          <Field
            id="deny-reason"
            label="Because"
            hint="Required — an entry with no reason is a mystery six months from now."
          >
            <input
              id="deny-reason"
              className={INPUT}
              value={reason}
              required
              minLength={3}
              maxLength={255}
              onChange={(event) => setReason(event.target.value)}
            />
          </Field>

          <Button
            type="submit"
            variant="secondary"
            size="sm"
            disabled={busy || reason.trim().length < 3 || (where !== "everywhere" && scopeId === "")}
          >
            Deny
          </Button>
        </form>
      )}

      <div className="space-y-2 border border-n-200 p-3 rounded-md">
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(event) => {
            event.preventDefault();

            startAction(async () => {
              const result = await explainPermission(membershipId, asked);

              setError(result.error);
              setExplanation(result.explanation);
            });
          }}
        >
          <Field id="explain-permission" label="Can they" hint="Where the answer comes from.">
            <select
              id="explain-permission"
              className={INPUT}
              value={asked}
              onChange={(event) => setAsked(event.target.value)}
            >
              {permissions.map((entry) => (
                <option key={entry.key} value={entry.key}>
                  {entry.key}
                </option>
              ))}
            </select>
          </Field>

          <Button type="submit" variant="ghost" size="sm" disabled={busy}>
            Explain
          </Button>
        </form>

        {explanation && (
          <div className="text-body-sm text-n-700" role="status">
            <p className="text-n-900">
              {explanation.permission}: {explanation.allowed ? "allowed" : "not allowed"}
            </p>

            {explanation.granted_by.length > 0 && (
              <p>Granted across the organization by {explanation.granted_by.join(", ")}.</p>
            )}

            {explanation.granted_on.map((grant, index) => (
              <p key={index}>
                Granted by {grant.role} on {grant.scope_type} {grant.scope_name ?? "—"}.
              </p>
            ))}

            {explanation.denied_by.map((denial, index) => (
              <p key={index} className="text-s-danger">
                Denied{" "}
                {denial.scope_type === null
                  ? "everywhere"
                  : `on ${denial.scope_type} ${denial.scope_name ?? "—"}`}
                : {denial.reason}
              </p>
            ))}

            {explanation.granted_by.length === 0 &&
              explanation.granted_on.length === 0 &&
              explanation.denied_by.length === 0 && <p>No role they hold carries it.</p>}
          </div>
        )}
      </div>
    </section>
  );
}
