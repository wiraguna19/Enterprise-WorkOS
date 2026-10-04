"use client";

import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { createApiToken, revokeApiToken, type TokenAccess } from "./api-token-actions";
import { useT } from "@/i18n/I18nProvider";

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
  const t = useT();

  // The permission's name sits inside the sentence as code, so the sentence is
  // cut at its placeholder rather than assembled from fragments in this file.
  const [cannotBefore, cannotAfter] = t("tok.cannotMake").split("{permission}");

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {issued && (
        <Panel id="issued" title={t("tok.issued", { name: issued.name })} tone="danger">
          <div className="space-y-3">
            <p className="text-body-sm text-n-700">
              <strong>{t("tok.shownOnce")}</strong> {t("tok.shownOnceTail")}
            </p>

            <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
              {issued.value}
            </code>

            <p className="text-caption text-n-500">{t("tok.bearer")}</p>
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
        id="tokens"
        title={t("tok.yours")}
        description={
          tokens.length === 0 ? t("tok.none") : t("tok.active", { count: tokens.length })
        }
        bleed
      >
        {tokens.length > 0 && (
          <DataTable caption={t("tok.caption")}>
            <THead>
              <Tr>
                <Th>{t("projects.col.name")}</Th>
                <Th>{t("tok.col.access")}</Th>
                <Th>{t("sess.col.lastUsed")}</Th>
                <Th>{t("tok.col.expires")}</Th>
                <Th align="right">{t("tok.col.actions")}</Th>
              </Tr>
            </THead>
            <TBody>
              {tokens.map((token) => (
                <Tr key={token.id}>
                  <Td>
                    <span className="font-medium">{token.name}</span>
                    <span className="block text-caption text-n-500">
                      {t("tok.made", { date: token.created })}
                    </span>
                  </Td>
                  <Td>
                    <Badge tone={token.access === "read" ? "neutral" : "warning"}>
                      {token.access === "read" ? t("tok.readOnly") : t("tok.readWrite")}
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
          {cannotBefore}
          <code className="font-mono">api_token.create</code>
          {cannotAfter}
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
  const t = useT();

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        {t("tok.revoke")}
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
        {t("tok.revokeConfirm", { name })}
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        {t("common.cancel")}
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
  const t = useT();
  const [name, setName] = useState("");
  const [access, setAccess] = useState<TokenAccess>("read");
  const [days, setDays] = useState(90);

  const nameId = useId();
  const daysId = useId();

  return (
    <Panel
      id="new-token"
      title={t("tok.make.title")}
      description={t("tok.make.description")}
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            variant="primary"
            size="sm"
            disabled={busy || name.trim() === ""}
            onClick={() => onCreate({ name: name.trim(), access, expiresInDays: days })}
          >
            {busy ? t("tok.making") : t("tok.make")}
          </Button>

          {name.trim() === "" && (
            <p role="status" className="text-caption text-n-500">
              {t("tok.nameIt")}
            </p>
          )}
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id={nameId} label={t("projects.col.name")} hint={t("tok.name.hint")}>
          <input
            id={nameId}
            value={name}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            className={INPUT}
          />
        </Field>

        <Field id={daysId} label={t("tok.expiresAfter")}>
          <select
            id={daysId}
            value={days}
            onChange={(event) => setDays(Number(event.target.value))}
            className={INPUT}
          >
            {[30, 90, 365].map((count) => (
              <option key={count} value={count}>
                {t("tok.days", { count })}
              </option>
            ))}
          </select>
        </Field>

        <fieldset className="sm:col-span-2 space-y-1">
          <legend className="mb-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
            {t("tok.col.access")}
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
              {t("tok.access.read")}
              <span className="block text-caption text-n-500">{t("tok.access.read.hint")}</span>
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
              {t("tok.access.write")}
              <span className="block text-caption text-n-500">{t("tok.access.write.hint")}</span>
            </span>
          </label>
        </fieldset>
      </div>
    </Panel>
  );
}
