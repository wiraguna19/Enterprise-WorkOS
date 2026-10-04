"use client";

import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import type { TokenAccess } from "./api-token-actions";
import {
  createServiceAccount,
  deactivateServiceAccount,
  issueServiceToken,
  revokeServiceToken,
} from "./service-account-actions";
import { useT } from "@/i18n/I18nProvider";

export type ServiceAccountRow = {
  id: string;
  name: string;
  active: boolean;
  role: { key: string; name: string } | null;
  /** Live tokens, formatted by the page in the viewer's zone. */
  tokens: Array<{ id: string; name: string; access: TokenAccess; lastUsed: string; expires: string }>;
};

/**
 * Settings → Service accounts (ADR 0059).
 *
 * An integration that acts in its own name, with a role chosen here, through
 * tokens issued here. The page says the three things that make it different
 * from a person's token, where somebody deciding between the two will read
 * them: it survives whoever set it up leaving; the activity log names it; and
 * it cannot sign in, hold work, or be an administrator.
 */
export function ServiceAccountPanel({
  accounts,
  roles,
  apiBase,
}: {
  accounts: ServiceAccountRow[];
  /** The roles it may hold — the API serves which it may not. */
  roles: Array<{ key: string; name: string }>;
  apiBase: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<{ account: string; value: string } | null>(null);
  const [busy, start] = useTransition();
  const t = useT();

  const run = (action: () => Promise<{ error: string | null }>) =>
    start(async () => setError((await action()).error));

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {issued && (
        <Panel id="issued" title={t("tok.issued", { name: issued.account })} tone="danger">
          <div className="space-y-3">
            <p className="text-body-sm text-n-700">
              <strong>{t("tok.shownOnce")}</strong> {t("svc.issued.tail")}
            </p>
            <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
              {issued.value}
            </code>
            <code className="block overflow-x-auto whitespace-pre rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-700">
              {`curl -H "Authorization: Bearer ${issued.value}" \\\n     ${apiBase}/auth/me`}
            </code>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" onClick={() => navigator.clipboard?.writeText(issued.value)}>
                {t("tok.copy")}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setIssued(null)}>
                {t("tok.saved")}
              </Button>
            </div>
          </div>
        </Panel>
      )}

      <Panel
        id="accounts"
        title={t("settings.serviceAccounts.label")}
        description={
          accounts.length === 0
            ? t("svc.none")
            : t("svc.active", { count: accounts.filter((account) => account.active).length })
        }
        bleed
      >
        {accounts.length > 0 && (
          <ul className="divide-y divide-n-100">
            {accounts.map((account) => (
              <AccountRow
                key={account.id}
                account={account}
                busy={busy}
                onIssue={(input) =>
                  start(async () => {
                    const result = await issueServiceToken(account.id, input);

                    setError(result.error);

                    if (result.token !== undefined) setIssued({ account: account.name, value: result.token });
                  })
                }
                onRevoke={(tokenId) => run(() => revokeServiceToken(account.id, tokenId))}
                onDeactivate={() => run(() => deactivateServiceAccount(account.id))}
              />
            ))}
          </ul>
        )}
      </Panel>

      <NewAccount roles={roles} busy={busy} onCreate={(input) => run(() => createServiceAccount(input))} />
    </div>
  );
}

function AccountRow({
  account,
  busy,
  onIssue,
  onRevoke,
  onDeactivate,
}: {
  account: ServiceAccountRow;
  busy: boolean;
  onIssue: (input: { name: string; access: TokenAccess; expiresInDays: number }) => void;
  onRevoke: (tokenId: string) => void;
  onDeactivate: () => void;
}) {
  const [tokenName, setTokenName] = useState("");
  const [access, setAccess] = useState<TokenAccess>("read");
  const [days, setDays] = useState(90);
  const [confirming, setConfirming] = useState(false);
  const t = useT();
  const nameId = useId();
  const accessId = useId();
  const daysId = useId();

  return (
    <li className="space-y-3 px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-medium text-n-900">
            {account.name}{" "}
            {!account.active && <Badge tone="neutral">{t("svc.deactivated")}</Badge>}
          </p>
          <p className="text-caption text-n-500">
            {account.role === null ? t("svc.noRole") : t("svc.role", { role: account.role.name })} ·{" "}
            {t.plural("svc.tokens", account.tokens.length)}
          </p>
        </div>

        {account.active &&
          (confirming ? (
            <span className="flex items-center gap-1.5">
              <span className="text-caption text-n-700">{t("svc.deactivate.warning")}</span>
              <Button variant="destructive" size="sm" disabled={busy} onClick={onDeactivate}>
                {t("svc.deactivate")}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                {t("svc.keep")}
              </Button>
            </span>
          ) : (
            <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
              {t("svc.deactivateArm")}
            </Button>
          ))}
      </div>

      {account.tokens.length > 0 && (
        <DataTable caption={t("svc.tokensOf", { name: account.name })}>
          <THead>
            <Tr>
              <Th>{t("svc.col.token")}</Th>
              <Th>{t("tok.col.access")}</Th>
              <Th>{t("sess.col.lastUsed")}</Th>
              <Th>{t("tok.col.expires")}</Th>
              <Th align="right">{t("tok.col.actions")}</Th>
            </Tr>
          </THead>
          <TBody>
            {account.tokens.map((token) => (
              <Tr key={token.id}>
                <Td>{token.name}</Td>
                <Td>
                  <Badge tone={token.access === "read" ? "neutral" : "warning"}>
                    {token.access === "read" ? t("tok.readOnly") : t("tok.readWrite")}
                  </Badge>
                </Td>
                <Td muted>{token.lastUsed}</Td>
                <Td muted>{token.expires}</Td>
                <Td align="right">
                  <Button variant="destructive" size="sm" disabled={busy} onClick={() => onRevoke(token.id)}>
                    {t("tok.revoke")}
                  </Button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </DataTable>
      )}

      {account.active && (
        <form
          aria-label={t("svc.issueFor", { name: account.name })}
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            onIssue({ name: tokenName.trim(), access, expiresInDays: days });
            setTokenName("");
          }}
        >
          <Field id={nameId} label={t("svc.tokenName")} className="min-w-40 flex-1">
            <input
              id={nameId}
              value={tokenName}
              maxLength={80}
              onChange={(event) => setTokenName(event.target.value)}
              placeholder={t("svc.tokenName.placeholder")}
              className={INPUT}
            />
          </Field>
          <Field id={accessId} label={t("tok.col.access")}>
            <select
              id={accessId}
              value={access}
              onChange={(event) => setAccess(event.target.value as TokenAccess)}
              className={INPUT}
            >
              <option value="read">{t("tok.access.read")}</option>
              <option value="read_write">{t("tok.access.write")}</option>
            </select>
          </Field>
          <Field id={daysId} label={t("tok.expiresAfter")}>
            <select
              id={daysId}
              value={days}
              onChange={(event) => setDays(Number(event.target.value))}
              className={INPUT}
            >
              <option value={30}>{t("tok.days", { count: 30 })}</option>
              <option value={90}>{t("tok.days", { count: 90 })}</option>
              <option value={365}>{t("svc.aYear")}</option>
            </select>
          </Field>
          <Button type="submit" variant="affirmative" size="sm" disabled={busy || tokenName.trim() === ""}>
            {t("svc.issue")}
          </Button>
        </form>
      )}
    </li>
  );
}

function NewAccount({
  roles,
  busy,
  onCreate,
}: {
  roles: Array<{ key: string; name: string }>;
  busy: boolean;
  onCreate: (input: { name: string; role: string }) => void;
}) {
  const [name, setName] = useState("");
  const [role, setRole] = useState("");
  const t = useT();
  const nameId = useId();
  const roleId = useId();

  return (
    <Panel
      id="new-service-account"
      title={t("svc.add.title")}
      description={t("svc.add.description")}
    >
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          onCreate({ name: name.trim(), role });
          setName("");
        }}
      >
        <Field
          id={nameId}
          label={t("projects.col.name")}
          hint={t("svc.name.hint")}
          className="min-w-48 flex-1"
        >
          <input
            id={nameId}
            value={name}
            maxLength={160}
            onChange={(event) => setName(event.target.value)}
            placeholder={t("svc.name.placeholder")}
            className={INPUT}
          />
        </Field>
        <Field id={roleId} label={t("members.col.role")}>
          <select id={roleId} value={role} onChange={(event) => setRole(event.target.value)} className={INPUT}>
            <option value="">{t("svc.chooseRole")}</option>
            {roles.map((option) => (
              <option key={option.key} value={option.key}>
                {option.name}
              </option>
            ))}
          </select>
        </Field>
        <Button type="submit" variant="primary" size="sm" disabled={busy || name.trim().length < 2 || role === ""}>
          {t("svc.add")}
        </Button>
      </form>
    </Panel>
  );
}
