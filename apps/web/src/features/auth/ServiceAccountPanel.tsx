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
        <Panel id="issued" title={`Token for ${issued.account}`} tone="danger">
          <div className="space-y-3">
            <p className="text-body-sm text-n-700">
              <strong>It is shown once.</strong> Only a digest of it is kept, so if it is lost, revoke
              it and issue another.
            </p>
            <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
              {issued.value}
            </code>
            <code className="block overflow-x-auto whitespace-pre rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-700">
              {`curl -H "Authorization: Bearer ${issued.value}" \\\n     ${apiBase}/auth/me`}
            </code>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" onClick={() => navigator.clipboard?.writeText(issued.value)}>
                Copy the token
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setIssued(null)}>
                I have saved it
              </Button>
            </div>
          </div>
        </Panel>
      )}

      <Panel
        id="accounts"
        title="Service accounts"
        description={
          accounts.length === 0
            ? "None yet. A service account is an integration that acts in its own name, with a role you choose — and keeps working when the person who set it up leaves."
            : `${accounts.filter((account) => account.active).length} active. The activity log names each one as the actor of what it does.`
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
  const nameId = useId();
  const accessId = useId();
  const daysId = useId();

  return (
    <li className="space-y-3 px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-medium text-n-900">
            {account.name}{" "}
            {!account.active && <Badge tone="neutral">deactivated</Badge>}
          </p>
          <p className="text-caption text-n-500">
            {account.role === null ? "No role" : `Role: ${account.role.name}`} ·{" "}
            {account.tokens.length === 1 ? "1 token" : `${account.tokens.length} tokens`}
          </p>
        </div>

        {account.active &&
          (confirming ? (
            <span className="flex items-center gap-1.5">
              <span className="text-caption text-n-700">Its tokens stop working at once.</span>
              <Button variant="destructive" size="sm" disabled={busy} onClick={onDeactivate}>
                Deactivate
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                Keep
              </Button>
            </span>
          ) : (
            <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
              Deactivate…
            </Button>
          ))}
      </div>

      {account.tokens.length > 0 && (
        <DataTable caption={`Tokens of ${account.name}`}>
          <THead>
            <Tr>
              <Th>Token</Th>
              <Th>Access</Th>
              <Th>Last used</Th>
              <Th>Expires</Th>
              <Th align="right">Actions</Th>
            </Tr>
          </THead>
          <TBody>
            {account.tokens.map((token) => (
              <Tr key={token.id}>
                <Td>{token.name}</Td>
                <Td>
                  <Badge tone={token.access === "read" ? "neutral" : "warning"}>
                    {token.access === "read" ? "read only" : "read and write"}
                  </Badge>
                </Td>
                <Td muted>{token.lastUsed}</Td>
                <Td muted>{token.expires}</Td>
                <Td align="right">
                  <Button variant="destructive" size="sm" disabled={busy} onClick={() => onRevoke(token.id)}>
                    Revoke
                  </Button>
                </Td>
              </Tr>
            ))}
          </TBody>
        </DataTable>
      )}

      {account.active && (
        <form
          aria-label={`Issue a token for ${account.name}`}
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            onIssue({ name: tokenName.trim(), access, expiresInDays: days });
            setTokenName("");
          }}
        >
          <Field id={nameId} label="Token name" className="min-w-40 flex-1">
            <input
              id={nameId}
              value={tokenName}
              maxLength={80}
              onChange={(event) => setTokenName(event.target.value)}
              placeholder="e.g. Nightly sync"
              className={INPUT}
            />
          </Field>
          <Field id={accessId} label="Access">
            <select
              id={accessId}
              value={access}
              onChange={(event) => setAccess(event.target.value as TokenAccess)}
              className={INPUT}
            >
              <option value="read">Read only</option>
              <option value="read_write">Read and write</option>
            </select>
          </Field>
          <Field id={daysId} label="Expires after">
            <select
              id={daysId}
              value={days}
              onChange={(event) => setDays(Number(event.target.value))}
              className={INPUT}
            >
              <option value={30}>30 days</option>
              <option value={90}>90 days</option>
              <option value={365}>A year</option>
            </select>
          </Field>
          <Button type="submit" variant="affirmative" size="sm" disabled={busy || tokenName.trim() === ""}>
            Issue token
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
  const nameId = useId();
  const roleId = useId();

  return (
    <Panel
      id="new-service-account"
      title="Add a service account"
      description="Give it the narrowest role that does the job. It cannot be an administrator, cannot sign in, and cannot be given work to hold."
    >
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          onCreate({ name: name.trim(), role });
          setName("");
        }}
      >
        <Field id={nameId} label="Name" hint="What the activity log will show it as." className="min-w-48 flex-1">
          <input
            id={nameId}
            value={name}
            maxLength={160}
            onChange={(event) => setName(event.target.value)}
            placeholder="e.g. Warehouse integration"
            className={INPUT}
          />
        </Field>
        <Field id={roleId} label="Role">
          <select id={roleId} value={role} onChange={(event) => setRole(event.target.value)} className={INPUT}>
            <option value="">Choose a role…</option>
            {roles.map((option) => (
              <option key={option.key} value={option.key}>
                {option.name}
              </option>
            ))}
          </select>
        </Field>
        <Button type="submit" variant="primary" size="sm" disabled={busy || name.trim().length < 2 || role === ""}>
          Add service account
        </Button>
      </form>
    </Panel>
  );
}
