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
import {
  deleteSsoConnection,
  saveSsoConnection,
  setSsoEnforced,
  verifySsoDomain,
} from "./sso-connection-actions";
import { useLocale, useT } from "@/i18n/I18nProvider";

export type SsoConnection = {
  id: string;
  idp_entity_id: string;
  idp_sso_url: string;
  idp_certificate: string;
  domains: string[];
  /** Each domain's DNS proof. Unproven domains sign nobody in. */
  domain_verification: DomainProof[];
  enforced: boolean;
  last_succeeded_at: string | null;
  updated_at: string;
};

export type DomainProof = {
  domain: string;
  verified: boolean;
  verified_at: string | null;
  record_name: string;
  record_value: string;
};

export type SsoSettings = {
  connection: SsoConnection | null;
  service_provider: { entity_id: string; acs_url: string; metadata_url: string };
  password_sessions: number;
  /** Who could sign in neither way once it is required (ADR 0052). */
  members_outside_domains: Array<{ membership_id: string; name: string; email: string }>;
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
export function SsoConnectionPanel({
  settings,
  timeZone,
}: {
  settings: SsoSettings;
  timeZone: string;
}) {
  const { connection, service_provider: sp } = settings;
  const t = useT();

  return (
    <>
      <Panel
        id="sso-service-provider"
        title={t("sso.sp.title")}
        description={t("sso.sp.description")}
      >
        <KeyValue columns={2}>
          <KeyValueItem label={t("sso.sp.entityId")}>
            <code className="font-mono text-caption">{sp.entity_id}</code>
          </KeyValueItem>
          <KeyValueItem label={t("sso.sp.acs")}>
            <code className="font-mono text-caption">{sp.acs_url}</code>
          </KeyValueItem>
          <KeyValueItem label={t("sso.sp.metadata")}>
            <code className="font-mono text-caption">{sp.metadata_url}</code>
          </KeyValueItem>
          <KeyValueItem label={t("sso.sp.nameId")}>
            <span>{t("sso.sp.nameId.value")}</span>
          </KeyValueItem>
        </KeyValue>
      </Panel>

      <ConnectionForm connection={connection} timeZone={timeZone} />

      {connection !== null && <DomainProofPanel proofs={connection.domain_verification} />}

      {connection !== null && (
        <EnforcementPanel
          connection={connection}
          passwordSessions={settings.password_sessions}
          outside={settings.members_outside_domains}
        />
      )}
    </>
  );
}

function ConnectionForm({
  connection,
  timeZone,
}: {
  connection: SsoConnection | null;
  timeZone: string;
}) {
  const [entityId, setEntityId] = useState(connection?.idp_entity_id ?? "");
  const [ssoUrl, setSsoUrl] = useState(connection?.idp_sso_url ?? "");
  const [certificate, setCertificate] = useState(connection?.idp_certificate ?? "");
  const [domains, setDomains] = useState((connection?.domains ?? []).join("\n"));
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState<"save" | "delete" | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

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
        toast({ message: connection === null ? t("sso.toast.connected") : t("sso.toast.saved") });
      }
    });

  const remove = () =>
    start(async () => {
      const result = await deleteSsoConnection();

      setNeedsPassword(result.needsPassword ? "delete" : null);
      setError(result.error);

      if (result.error === null && !result.needsPassword) {
        toast({ tone: "removed", message: t("sso.toast.removed") });
      }
    });

  return (
    <Panel
      id="sso-identity-provider"
      title={t("sso.idp.title")}
      description={t("sso.idp.description")}
      actions={
        connection === null ? (
          <Badge tone="neutral" icon="minus">{t("sso.badge.notSetUp")}</Badge>
        ) : connection.last_succeeded_at === null ? (
          <Badge tone="warning">{t("sso.badge.neverUsed")}</Badge>
        ) : (
          <Badge tone="success" icon="check">{t("sso.badge.working")}</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-prose space-y-3">
        <Field id="sso-entity-id" label={t("sso.idp.entityId")}>
          <input
            id="sso-entity-id"
            value={entityId}
            onChange={(event) => setEntityId(event.target.value)}
            placeholder="https://idp.example.com/saml"
            className={INPUT}
          />
        </Field>

        <Field id="sso-url" label={t("sso.idp.url")} hint={t("sso.idp.url.hint")}>
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
          label={t("sso.idp.certificate")}
          hint={t("sso.idp.certificate.hint")}
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
          label={t("sso.idp.domains")}
          hint={t("sso.idp.domains.hint")}
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
            {t("sso.lastSucceeded", {
              when: formatDateTime(connection.last_succeeded_at, timeZone, locale),
            })}
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button variant="primary" size="sm" disabled={busy} onClick={save}>
            {busy ? t("common.saving") : connection === null ? t("sso.connect") : t("common.save")}
          </Button>

          {connection !== null && (
            <Button
              variant="destructive"
              size="sm"
              disabled={busy || connection.enforced}
              title={connection.enforced ? t("sso.removeFirst") : undefined}
              onClick={remove}
            >
              {t("sso.remove")}
            </Button>
          )}
        </div>

        {needsPassword !== null && (
          <ConfirmPassword
            action={needsPassword === "save" ? t("sso.confirm.save") : t("sso.confirm.remove")}
            locale={locale}
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

/**
 * Proving each domain (DNS TXT).
 *
 * A domain typed into the form is a claim; it signs nobody in until a record
 * only its real owner could publish is found. The record's name and value are
 * shown in full and selectable, because the next thing the reader does is
 * paste them into a DNS console.
 */
function DomainProofPanel({ proofs }: { proofs: DomainProof[] }) {
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const verify = (domain: string) =>
    start(async () => {
      const result = await verifySsoDomain(domain);

      setNeedsPassword(result.needsPassword ? domain : null);
      setError(result.error);

      if (result.error === null && !result.needsPassword) {
        toast({ tone: "done", message: t("sso.domains.toast", { domain }) });
      }
    });

  const pending = proofs.filter((proof) => !proof.verified).length;

  return (
    <Panel
      id="sso-domains-proof"
      title={t("sso.domains.title")}
      description={t("sso.domains.description")}
      actions={
        pending === 0 ? (
          <Badge tone="success" icon="check">{t("sso.domains.allProven")}</Badge>
        ) : (
          <Badge tone="warning">{t.plural("sso.domains.pending", pending)}</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <ul className="divide-y divide-n-100">
        {proofs.map((proof) => (
          <li key={proof.domain} className="space-y-2 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium text-n-900">{proof.domain}</span>
              {proof.verified ? (
                <Badge tone="success" icon="check">{t("sso.domains.proven")}</Badge>
              ) : (
                <Badge tone="warning">{t("sso.domains.notProven")}</Badge>
              )}
            </div>

            {!proof.verified && (
              <>
                <KeyValue columns={2}>
                  <KeyValueItem label={t("sso.domains.recordName")}>
                    <code className="select-all break-all font-mono text-caption">{proof.record_name}</code>
                  </KeyValueItem>
                  <KeyValueItem label={t("sso.domains.recordValue")}>
                    <code className="select-all break-all font-mono text-caption">{proof.record_value}</code>
                  </KeyValueItem>
                </KeyValue>

                <Button variant="secondary" size="sm" disabled={busy} onClick={() => verify(proof.domain)}>
                  {busy ? t("sso.domains.checking") : t("sso.domains.check")}
                </Button>

                {needsPassword === proof.domain && (
                  <ConfirmPassword
                    action={t("sso.confirm.verify", { domain: proof.domain })}
                    locale={locale}
                    onConfirmed={() => {
                      setNeedsPassword(null);
                      verify(proof.domain);
                    }}
                  />
                )}
              </>
            )}
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function EnforcementPanel({
  connection,
  passwordSessions,
  outside,
}: {
  connection: SsoConnection;
  passwordSessions: number;
  outside: SsoSettings["members_outside_domains"];
}) {
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

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
            ? t("sso.toast.passwordsBack")
            : t.plural("sso.toast.required", result.ended ?? 0),
        });
      }
    });

  return (
    <Panel
      id="sso-enforcement"
      title={t("sso.enforce.title")}
      description={t("sso.enforce.description")}
      actions={
        connection.enforced ? (
          <Badge tone="success" icon="check">{t("mfapol.badge.required")}</Badge>
        ) : (
          <Badge tone="neutral" icon="minus">{t("mfapol.badge.optional")}</Badge>
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
            ? t("sso.enforce.body.on")
            : t("sso.enforce.body.off")}
        </p>

        {!connection.enforced && untested && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {t("sso.enforce.untested")}
          </p>
        )}

        {!connection.enforced && !untested && passwordSessions > 0 && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {t.plural("sso.enforce.sessions", passwordSessions)}
          </p>
        )}

        {outside.length > 0 && (
          <LockedOut people={outside} enforced={connection.enforced} />
        )}

        <Button
          variant={connection.enforced ? "destructive" : "affirmative"}
          size="sm"
          disabled={busy || (!connection.enforced && untested)}
          onClick={toggle}
        >
          {busy ? t("common.saving") : connection.enforced ? t("mfapol.stop") : t("mfapol.require")}
        </Button>

        {needsPassword && (
          <ConfirmPassword
            action={connection.enforced ? t("sso.confirm.off") : t("sso.confirm.on")}
            locale={locale}
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

/**
 * People outside every SSO domain, by name (ADR 0052).
 *
 * Shown whether or not it is already required: before, it is the warning;
 * after, it is the list of people who currently cannot get in. Named, not
 * counted — "3 people" does not tell an administrator whether it is the
 * contractors they meant to exclude or the manager down the hall.
 */
function LockedOut({
  people,
  enforced,
}: {
  people: SsoSettings["members_outside_domains"];
  enforced: boolean;
}) {
  const shown = people.slice(0, 8);
  const t = useT();

  return (
    <div className="rounded-md border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
      <p>
        {t.plural(enforced ? "sso.locked.enforced" : "sso.locked.would", people.length)}
      </p>
      <ul className="mt-1 list-disc pl-5">
        {shown.map((person) => (
          <li key={person.membership_id}>
            {person.name} <span className="text-n-500">({person.email})</span>
          </li>
        ))}
      </ul>
      {people.length > shown.length && (
        <p className="mt-1">{t("sso.locked.more", { count: people.length - shown.length })}</p>
      )}
      <p className="mt-1">{t("sso.locked.fix")}</p>
    </div>
  );
}
