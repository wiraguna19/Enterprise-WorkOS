"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field, INPUT } from "@/components/ui/Field";
import { KeyValue, KeyValueItem } from "@/components/ui/KeyValue";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { formatDateTime } from "@/lib/format";
import { deleteSsoConnection, saveSsoConnection, setSsoEnforced } from "./sso-connection-actions";

export type SsoConnection = {
  id: string;
  idp_entity_id: string;
  idp_sso_url: string;
  idp_certificate: string;
  domains: string[];
  enforced: boolean;
  last_succeeded_at: string | null;
  updated_at: string;
};

export type SsoSettings = {
  connection: SsoConnection | null;
  service_provider: { entity_id: string; acs_url: string; metadata_url: string };
  password_sessions: number;
};

/**
 * An organization's identity provider, in three panels (ADR 0052).
 *
 *   1. **What the IdP needs from us** — shown first and always, because it is
 *      the first thing an IdP administrator asks for, before there is anything
 *      to configure here.
 *   2. **The IdP** — its entity id, where to send people, its certificate, and
 *      the email domains it signs in.
 *   3. **Requiring it** — which cannot be pressed until somebody has actually
 *      signed in through the connection, and says before it is pressed how
 *      many password sessions it will end.
 *
 * Every write may come back asking for the password (ADR 0034); the box
 * appears under the control that was refused and runs the act again.
 */
export function SsoConnectionPanel({ settings }: { settings: SsoSettings }) {
  const { connection, service_provider: sp } = settings;

  return (
    <>
      <Panel
        id="sso-service-provider"
        title="For your identity provider"
        description="Give these to whoever administers your IdP (Okta, Entra ID, Google Workspace…), or let it import the metadata URL."
      >
        <KeyValue columns={2}>
          <KeyValueItem label="Entity ID (audience)">
            <code className="font-mono text-caption">{sp.entity_id}</code>
          </KeyValueItem>
          <KeyValueItem label="ACS URL (reply URL)">
            <code className="font-mono text-caption">{sp.acs_url}</code>
          </KeyValueItem>
          <KeyValueItem label="Metadata URL">
            <code className="font-mono text-caption">{sp.metadata_url}</code>
          </KeyValueItem>
          <KeyValueItem label="Name ID">
            <span>The person&apos;s email address</span>
          </KeyValueItem>
        </KeyValue>
      </Panel>

      <ConnectionForm connection={connection} />

      {connection !== null && (
        <EnforcementPanel connection={connection} passwordSessions={settings.password_sessions} />
      )}
    </>
  );
}

function ConnectionForm({ connection }: { connection: SsoConnection | null }) {
  const [entityId, setEntityId] = useState(connection?.idp_entity_id ?? "");
  const [ssoUrl, setSsoUrl] = useState(connection?.idp_sso_url ?? "");
  const [certificate, setCertificate] = useState(connection?.idp_certificate ?? "");
  const [domains, setDomains] = useState((connection?.domains ?? []).join("\n"));
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState<"save" | "delete" | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();

  const save = () =>
    start(async () => {
      const result = await saveSsoConnection({
        idp_entity_id: entityId,
        idp_sso_url: ssoUrl,
        idp_certificate: certificate,
        // One per line, or separated by commas or spaces — however it was
        // pasted. The API normalises case and a stray @.
        domains: domains.split(/[\s,]+/).filter((domain) => domain !== ""),
      });

      setNeedsPassword(result.needsPassword ? "save" : null);
      setError(result.error);

      if (result.error === null && !result.needsPassword) {
        toast({ message: connection === null ? "Identity provider connected." : "Identity provider saved." });
      }
    });

  const remove = () =>
    start(async () => {
      const result = await deleteSsoConnection();

      setNeedsPassword(result.needsPassword ? "delete" : null);
      setError(result.error);

      if (result.error === null && !result.needsPassword) {
        toast({ tone: "removed", message: "Single sign-on removed. Everybody signs in with a password again." });
      }
    });

  return (
    <Panel
      id="sso-identity-provider"
      title="Identity provider"
      description="Copied from your IdP's SAML settings or metadata."
      actions={
        connection === null ? (
          <Badge tone="neutral" icon="minus">not set up</Badge>
        ) : connection.last_succeeded_at === null ? (
          <Badge tone="warning">never used</Badge>
        ) : (
          <Badge tone="success" icon="check">working</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-prose space-y-3">
        <Field id="sso-entity-id" label="IdP entity ID (issuer)">
          <input
            id="sso-entity-id"
            value={entityId}
            onChange={(event) => setEntityId(event.target.value)}
            placeholder="https://idp.example.com/saml"
            className={INPUT}
          />
        </Field>

        <Field id="sso-url" label="Sign-in URL" hint="Where people are sent to sign in. HTTPS only.">
          <input
            id="sso-url"
            value={ssoUrl}
            onChange={(event) => setSsoUrl(event.target.value)}
            placeholder="https://idp.example.com/sso"
            className={INPUT}
          />
        </Field>

        <Field
          id="sso-certificate"
          label="Signing certificate"
          hint="The whole certificate, including the BEGIN and END lines. It is public — the IdP's private key never leaves the IdP."
        >
          <textarea
            id="sso-certificate"
            value={certificate}
            onChange={(event) => setCertificate(event.target.value)}
            rows={6}
            spellCheck={false}
            placeholder="-----BEGIN CERTIFICATE-----"
            className={`${INPUT} font-mono text-caption`}
          />
        </Field>

        <Field
          id="sso-domains"
          label="Email domains"
          hint="One per line. Only these addresses can sign in through this IdP, and a domain can belong to one organization only."
        >
          <textarea
            id="sso-domains"
            value={domains}
            onChange={(event) => setDomains(event.target.value)}
            rows={3}
            spellCheck={false}
            placeholder="acme.com"
            className={INPUT}
          />
        </Field>

        {connection?.last_succeeded_at && (
          <p className="text-caption text-n-500">
            Last signed somebody in {formatDateTime(connection.last_succeeded_at)}.
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button variant="primary" size="sm" disabled={busy} onClick={save}>
            {busy ? "Saving…" : connection === null ? "Connect" : "Save"}
          </Button>

          {connection !== null && (
            <Button
              variant="destructive"
              size="sm"
              disabled={busy || connection.enforced}
              title={connection.enforced ? "Stop requiring single sign-on first." : undefined}
              onClick={remove}
            >
              Remove single sign-on
            </Button>
          )}
        </div>

        {needsPassword !== null && (
          <ConfirmPassword
            action={needsPassword === "save" ? "change your identity provider" : "remove single sign-on"}
            onConfirmed={() => {
              const again = needsPassword;

              setNeedsPassword(null);

              if (again === "save") save();
              else remove();
            }}
          />
        )}
      </div>
    </Panel>
  );
}

function EnforcementPanel({
  connection,
  passwordSessions,
}: {
  connection: SsoConnection;
  passwordSessions: number;
}) {
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, start] = useTransition();
  const toast = useToast();

  const untested = connection.last_succeeded_at === null;

  const toggle = () =>
    start(async () => {
      const result = await setSsoEnforced(!connection.enforced);

      setNeedsPassword(result.needsPassword === true);
      setError(result.error);

      if (result.error === null && !result.needsPassword) {
        toast({
          tone: connection.enforced ? "removed" : "done",
          message: connection.enforced
            ? "Passwords work here again."
            : `Single sign-on is required. ${result.ended ?? 0} password ${result.ended === 1 ? "session" : "sessions"} ended.`,
        });
      }
    });

  return (
    <Panel
      id="sso-enforcement"
      title="Require single sign-on"
      description="Whether a password still gets anybody into this organization."
      actions={
        connection.enforced ? (
          <Badge tone="success" icon="check">required</Badge>
        ) : (
          <Badge tone="neutral" icon="minus">optional</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-prose space-y-3">
        <p className="text-body-sm text-n-700">
          {connection.enforced
            ? "People sign in through your identity provider. Passwords are refused — except for people who administer single sign-on, so a broken IdP never locks out the ones who can fix it. Every such sign-in is in the audit log."
            : "People may sign in either way. Requiring it refuses passwords here, and ends every session a password opened."}
        </p>

        {!connection.enforced && untested && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            Nobody has signed in through this connection yet. Sign in with single sign-on once —
            in a private window, so you keep this session — before requiring it of everybody.
          </p>
        )}

        {!connection.enforced && !untested && passwordSessions > 0 && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {passwordSessions} {passwordSessions === 1 ? "session was" : "sessions were"} opened
            with a password and will end. Yours stays.
          </p>
        )}

        <Button
          variant={connection.enforced ? "destructive" : "affirmative"}
          size="sm"
          disabled={busy || (!connection.enforced && untested)}
          onClick={toggle}
        >
          {busy ? "Saving…" : connection.enforced ? "Stop requiring it" : "Require it"}
        </Button>

        {needsPassword && (
          <ConfirmPassword
            action={connection.enforced ? "let passwords in again" : "require single sign-on of everybody here"}
            onConfirmed={() => {
              setNeedsPassword(false);
              toggle();
            }}
          />
        )}
      </div>
    </Panel>
  );
}
