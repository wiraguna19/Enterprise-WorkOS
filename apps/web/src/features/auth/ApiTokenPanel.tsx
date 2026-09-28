"use client";

import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { createApiToken, revokeApiToken, type TokenAccess } from "./api-token-actions";

/**
 * A token, as the page hands it over: dates already formatted in the viewer's
 * own time zone, because a Server Component may give a Client Component
 * values, never functions.
 */
export type ApiTokenRow = {
  id: string;
  name: string;
  access: TokenAccess;
  created: string;
  lastUsed: string;
  expires: string;
};

/**
 * Tokens for scripts and integrations, acting as you (ADR 0049).
 *
 * What the screen says out loud, because each one is a surprise otherwise:
 * a token can do what YOU can, never more; it is shown once; read-only means
 * read-only; it never goes idle but it does expire; and turning your second
 * factor on or off ends it, along with every other way of acting as you.
 */
export function ApiTokenPanel({
  tokens,
  mayCreate,
  apiBase,
}: {
  tokens: ApiTokenRow[];
  mayCreate: boolean;
  /** Where the API lives, so the example below is one somebody can paste. */
  apiBase: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<{ name: string; value: string } | null>(null);
  const [busy, start] = useTransition();

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {issued && (
        <Panel id="issued" title={`Token for ${issued.name}`} tone="danger">
          <div className="space-y-3">
            <p className="text-body-sm text-n-700">
              <strong>It is shown once.</strong> Only a digest of it is kept, so if it is lost, revoke
              it and make another.
            </p>

            <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
              {issued.value}
            </code>

            <p className="text-caption text-n-500">Sent as a bearer token:</p>
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
        id="tokens"
        title="Your tokens"
        description={
          tokens.length === 0
            ? "None. A token lets a script act as you in this organization, with your permissions and no more."
            : `${tokens.length} active in this organization.`
        }
        bleed
      >
        {tokens.length > 0 && (
          <DataTable caption="Your API tokens in this organization">
            <THead>
              <Tr>
                <Th>Name</Th>
                <Th>Access</Th>
                <Th>Last used</Th>
                <Th>Expires</Th>
                <Th align="right">Actions</Th>
              </Tr>
            </THead>
            <TBody>
              {tokens.map((token) => (
                <Tr key={token.id}>
                  <Td>
                    <span className="font-medium">{token.name}</span>
                    <span className="block text-caption text-n-500">Made {token.created}</span>
                  </Td>
                  <Td>
                    <Badge tone={token.access === "read" ? "neutral" : "warning"}>
                      {token.access === "read" ? "read only" : "read and write"}
                    </Badge>
                  </Td>
                  <Td muted>{token.lastUsed}</Td>
                  <Td muted>{token.expires}</Td>
                  <Td align="right">
                    <RevokeToken
                      name={token.name}
                      disabled={busy}
                      onConfirm={() =>
                        start(async () => {
                          const result = await revokeApiToken(token.id);
                          setError(result.error);
                        })
                      }
                    />
                  </Td>
                </Tr>
              ))}
            </TBody>
          </DataTable>
        )}
      </Panel>

      {mayCreate ? (
        <NewToken
          key={`new-${tokens.length}`}
          busy={busy}
          onCreate={(input) =>
            start(async () => {
              const result = await createApiToken(input);

              setError(result.error);

              if (result.token !== undefined) setIssued({ name: input.name, value: result.token });
            })
          }
        />
      ) : (
        // Said, not hidden: somebody looking for the button needs to know it
        // is a role decision, not a missing feature.
        <p className="text-caption text-n-500">
          Your role does not include making API tokens (the <code className="font-mono">api_token.create</code>{" "}
          permission). An administrator can grant it.
        </p>
      )}
    </div>
  );
}

function RevokeToken({
  name,
  disabled,
  onConfirm,
}: {
  name: string;
  disabled: boolean;
  onConfirm: () => void;
}) {
  const [armed, setArmed] = useState(false);

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        Revoke
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
        Revoke {name} — anything using it stops now
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        Cancel
      </Button>
    </span>
  );
}

function NewToken({
  busy,
  onCreate,
}: {
  busy: boolean;
  onCreate: (input: { name: string; access: TokenAccess; expiresInDays: number }) => void;
}) {
  const [name, setName] = useState("");
  const [access, setAccess] = useState<TokenAccess>("read");
  const [days, setDays] = useState(90);

  const nameId = useId();
  const daysId = useId();

  return (
    <Panel
      id="new-token"
      title="Make a token"
      description="It acts as you, in this organization, with your permissions. It cannot manage sign-ins, second factors or other tokens, and it never goes idle — but it does expire."
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            variant="primary"
            size="sm"
            disabled={busy || name.trim() === ""}
            onClick={() => onCreate({ name: name.trim(), access, expiresInDays: days })}
          >
            {busy ? "Making…" : "Make the token"}
          </Button>

          {name.trim() === "" && (
            <p role="status" className="text-caption text-n-500">
              Name it after what will use it — that is how you will know which one to revoke.
            </p>
          )}
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id={nameId} label="Name" hint="What will use it, e.g. “Nightly export”.">
          <input
            id={nameId}
            value={name}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            className={INPUT}
          />
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
            <option value={365}>365 days</option>
          </select>
        </Field>

        <fieldset className="sm:col-span-2 space-y-1">
          <legend className="mb-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
            Access
          </legend>
          <label className="flex items-start gap-2 text-body-sm text-n-700">
            <input
              type="radio"
              name="token-access"
              checked={access === "read"}
              onChange={() => setAccess("read")}
              className="mt-0.5"
            />
            <span>
              Read only
              <span className="block text-caption text-n-500">Anything that changes data is refused.</span>
            </span>
          </label>
          <label className="flex items-start gap-2 text-body-sm text-n-700">
            <input
              type="radio"
              name="token-access"
              checked={access === "read_write"}
              onChange={() => setAccess("read_write")}
              className="mt-0.5"
            />
            <span>
              Read and write
              <span className="block text-caption text-n-500">
                Everything your role allows — create, edit, delete.
              </span>
            </span>
          </label>
        </fieldset>
      </div>
    </Panel>
  );
}
