"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { scopeName } from "@/i18n/labels";
import { useT } from "@/i18n/I18nProvider";
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
  const t = useT();
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const [permission, setPermission] = useState(permissions[0]?.key ?? "");
  const [where, setWhere] = useState<"everywhere" | keyof typeof scopes>("everywhere");
  const [scopeId, setScopeId] = useState("");
  const [reason, setReason] = useState("");

  const [asked, setAsked] = useState(permissions[0]?.key ?? "");
  const [explanation, setExplanation] = useState<Explanation | null>(null);

  const options = where === "everywhere" ? [] : scopes[where];

  return (
    <Panel
      id="denials"
      title={t("deny.title")}
      description={t("deny.desc")}
      actions={
        denials.length > 0 ? (
          <Badge tone="danger" icon="minus">
            {t.plural("deny.count", denials.length)}
          </Badge>
        ) : undefined
      }
      bleed
    >
      {error && (
        <p
          role="alert"
          className="border-b border-s-danger/40 bg-s-danger/5 px-4 py-2 text-body-sm text-s-danger"
        >
          {error}
        </p>
      )}

      {denials.length === 0 ? (
        <p className="px-4 py-3 text-body-sm text-n-500">
          {t("deny.none")}
        </p>
      ) : (
        <DataTable caption={t("deny.caption")}>
          <THead>
            <Tr>
              <Th>{t("deny.mayNot")}</Th>
              <Th>{t("deny.where")}</Th>
              <Th>{t("deny.because")}</Th>
              {mayManage && <Th width="w-20" align="right">{t("inv.col.action")}</Th>}
            </Tr>
          </THead>
          <TBody>
            {denials.map((denial) => (
              <Tr key={denial.id}>
                <Td>
                  <span className="font-mono text-body-sm">{denial.permission}</span>
                </Td>
                <Td muted>
                  {denial.scope_type === null
                    ? t("deny.everywhere")
                    : `${scopeName(denial.scope_type, t)} ${denial.scope_name ?? denial.scope_id}`}
                </Td>
                <Td muted>{denial.reason}</Td>
                {mayManage && (
                  <Td align="right">
                    <Button
                      variant="affirmative"
                      size="sm"
                      disabled={busy}
                      onClick={() =>
                        startAction(async () => {
                          const result = await liftDenial(membershipId, denial.id);

                          setError(result.error);

                          if (result.error === null) {
                            toast({ message: t("deny.lifted") });
                          }
                        })
                      }
                    >
                      {t("deny.lift")}
                    </Button>
                  </Td>
                )}
              </Tr>
            ))}
          </TBody>
        </DataTable>
      )}

      {mayManage && (
        <form
          className="flex flex-wrap items-end gap-3 border-t border-n-200 bg-n-25 px-4 py-3"
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
                toast({ tone: "removed", message: t("deny.taken") });
              }
            });
          }}
        >
          <Field
            id="deny-permission"
            label={t("deny.mayNot")}
            hint={t("deny.mayNotHint")}
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

          <Field id="deny-where" label={t("deny.where")}>
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
              <option value="everywhere">{t("deny.everywhere")}</option>
              <option value="team">{t("deny.onTeam")}</option>
              <option value="department">{t("deny.onDepartment")}</option>
              <option value="project">{t("deny.onProject")}</option>
            </select>
          </Field>

          {where !== "everywhere" && (
            <Field id="deny-scope" label={t("roles.which")}>
              <select
                id="deny-scope"
                className={INPUT}
                value={scopeId}
                required
                onChange={(event) => setScopeId(event.target.value)}
              >
                <option value="">{t("roles.choose")}</option>
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
            label={t("deny.because")}
            hint={t("deny.reasonHint")}
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
            variant="destructive"
            size="sm"
            disabled={busy || reason.trim().length < 3 || (where !== "everywhere" && scopeId === "")}
          >
            {t("deny.deny")}
          </Button>
        </form>
      )}

      <div className="space-y-2 border-t border-n-200 px-4 py-3">
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
          <Field id="explain-permission" label={t("deny.canThey")} hint={t("deny.canTheyHint")}>
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
            {t("deny.explain")}
          </Button>
        </form>

        {explanation && (
          <div className="text-body-sm text-n-700" role="status">
            <p className="text-n-900">
              {explanation.permission}: {explanation.allowed ? t("deny.allowed") : t("deny.notAllowed")}
            </p>

            {explanation.granted_by.length > 0 && (
              <p>{t("deny.grantedOrg", { roles: explanation.granted_by.join(", ") })}</p>
            )}

            {explanation.granted_on.map((grant, index) => (
              <p key={index}>
                {t("deny.grantedOn", { role: grant.role, scope: scopeName(grant.scope_type, t), name: grant.scope_name ?? "—" })}
              </p>
            ))}

            {explanation.denied_by.map((denial, index) => (
              <p key={index} className="text-s-danger">
                {t("deny.denied")}{" "}
                {denial.scope_type === null
                  ? t("deny.everywhere")
                  : t("roles.onScope", { scope: scopeName(denial.scope_type, t), name: denial.scope_name ?? "—" })}
                : {denial.reason}
              </p>
            ))}

            {explanation.granted_by.length === 0 &&
              explanation.granted_on.length === 0 &&
              explanation.denied_by.length === 0 && <p>{t("deny.noRole")}</p>}
          </div>
        )}
      </div>
    </Panel>
  );
}
