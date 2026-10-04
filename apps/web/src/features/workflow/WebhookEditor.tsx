"use client";

import Link from "next/link";
import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import {
  deleteEndpoint,
  registerEndpoint,
  rotateEndpointSecret,
  saveEndpoint,
  setEndpointActive,
  testEndpoint,
} from "./webhook-actions";
import { statusWords, eventWords, type WebhookEndpoint } from "./webhooks";
import { useT } from "@/i18n/I18nProvider";

/**
 * Where this organization's events may be sent (ADR 0048).
 *
 * Three things this screen is careful about:
 *
 *   1. **A secret is shown once.** When an endpoint is registered or its secret
 *      rotated, the secret appears in a panel that says so, and it is gone the
 *      moment the panel is dismissed. Nothing can show it again — the API has
 *      no route that returns it.
 *   2. **Switching off, rotating and deleting are separate controls.** Each is
 *      its own security act with its own audit entry; one "Edit" that could do
 *      all three would eventually do the wrong one.
 *   3. **Delete says what it would break.** The rules that send to an endpoint
 *      are listed on its row, and the API refuses the delete while there are
 *      any — the row says so before anybody tries.
 */
export function WebhookEditor({
  endpoints,
  subscribable,
  showingDeliveriesFor,
}: {
  endpoints: WebhookEndpoint[];
  /** The events an endpoint may subscribe to, as the API serves them. */
  subscribable: string[];
  /** The endpoint whose deliveries the page is listing, if any. */
  showingDeliveriesFor?: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [secret, setSecret] = useState<{ name: string; value: string } | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const t = useT();

  function run(action: () => Promise<{ error: string | null }>, done?: string): void {
    start(async () => {
      const result = await action();

      setError(result.error);
      setNotice(result.error === null && done !== undefined ? done : null);

      if (result.error === null) setEditing(null);
    });
  }

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {notice && (
        <p role="status" className="text-body-sm text-n-700">
          {notice}
        </p>
      )}

      {secret && <SecretOnce name={secret.name} value={secret.value} onDone={() => setSecret(null)} />}

      <Panel
        id="endpoints"
        title={t("hook.endpoints")}
        description={
          endpoints.length === 0
            ? t("hook.endpoints.none")
            : t("hook.endpoints.on", {
                on: endpoints.filter((endpoint) => endpoint.is_active).length,
                count: endpoints.length,
              })
        }
        bleed
      >
        {endpoints.length > 0 && (
          <DataTable caption={t("hook.endpoints.caption")}>
            <THead>
              <Tr>
                <Th>{t("projects.col.name")}</Th>
                <Th>{t("hook.col.usedBy")}</Th>
                <Th align="right">{t("tok.col.actions")}</Th>
              </Tr>
            </THead>
            <TBody>
              {endpoints.map((endpoint) => (
                <Tr key={endpoint.id}>
                  <Td>
                    <span className="font-medium">{endpoint.name}</span>
                    {!endpoint.is_active && (
                      <span className="ml-2">
                        <Badge tone="neutral">{t("hook.switchedOff")}</Badge>
                      </span>
                    )}
                    <span className="block break-all font-mono text-micro text-n-500">
                      {endpoint.url}
                    </span>
                    {endpoint.disabled_reason && (
                      <span className="block text-caption text-s-danger">{endpoint.disabled_reason}</span>
                    )}
                  </Td>
                  <Td muted>
                    {/* Both ways something reaches it, because both are what
                        a switch-off or a delete would silence. */}
                    <span className="block">
                      {endpoint.rules.length === 0
                        ? t("hook.noRules")
                        : t("hook.rules", { rules: endpoint.rules.join(", ") })}
                    </span>
                    <span className="block">
                      {endpoint.events.length === 0
                        ? t("hook.noSubscriptions")
                        : t("hook.every", {
                            events: endpoint.events.map((event) => eventWords(event, t)).join(", "),
                          })}
                    </span>
                  </Td>
                  <Td align="right">
                    <span className="inline-flex flex-wrap justify-end gap-1">
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy || !endpoint.is_active}
                        onClick={() =>
                          start(async () => {
                            const result = await testEndpoint(endpoint.id);

                            setError(result.error);
                            setNotice(
                              result.delivery === undefined
                                ? null
                                : t("hook.testSent", {
                                    name: endpoint.name,
                                    status: statusWords(result.delivery.status, t).toLowerCase(),
                                  }) +
                                    (result.delivery.last_error
                                      ? ` — ${result.delivery.last_error}`
                                      : "."),
                            );
                          })
                        }
                      >
                        {t("hook.sendTest")}
                      </Button>

                      {/* An anchor: it GOES to the same page, showing this
                          endpoint's deliveries, and survives a reload. */}
                      <Link
                        href={`/settings/webhooks?endpoint=${endpoint.id}#deliveries`}
                        aria-current={showingDeliveriesFor === endpoint.id ? "page" : undefined}
                        className="inline-flex items-center rounded-md border border-n-300 px-2 py-1 text-caption text-n-700 hover:bg-n-50"
                      >
                        {t("hook.deliveries")}
                      </Link>

                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy}
                        onClick={() => setEditing(editing === endpoint.id ? null : endpoint.id)}
                      >
                        {editing === endpoint.id ? t("hook.close") : t("hook.edit")}
                      </Button>

                      {endpoint.is_active ? (
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={busy}
                          onClick={() => run(() => setEndpointActive(endpoint.id, false), t("hook.isOff", { name: endpoint.name }))}
                        >
                          {t("hook.switchOff")}
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="affirmative"
                          disabled={busy}
                          onClick={() => run(() => setEndpointActive(endpoint.id, true), t("hook.isOn", { name: endpoint.name }))}
                        >
                          {t("hook.switchOn")}
                        </Button>
                      )}

                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy}
                        onClick={() =>
                          start(async () => {
                            const result = await rotateEndpointSecret(endpoint.id);

                            setError(result.error);
                            setNotice(null);

                            if (result.secret !== undefined) {
                              setSecret({ name: endpoint.name, value: result.secret });
                            }
                          })
                        }
                      >
                        {t("hook.newSecret")}
                      </Button>

                      <DeleteEndpoint
                        endpoint={endpoint}
                        disabled={busy}
                        onConfirm={() => run(() => deleteEndpoint(endpoint.id), t("hook.isDeleted", { name: endpoint.name }))}
                      />
                    </span>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </DataTable>
        )}
      </Panel>

      {endpoints
        .filter((endpoint) => endpoint.id === editing)
        .map((endpoint) => (
          <EndpointForm
            key={endpoint.id}
            id={`edit-${endpoint.id}`}
            title={t("hook.editTitle", { name: endpoint.name })}
            description={t("hook.edit.description")}
            submitLabel={t("common.save")}
            initial={endpoint}
            subscribable={subscribable}
            busy={busy}
            onSubmit={(input) => run(() => saveEndpoint(endpoint.id, input), t("hook.isSaved", { name: input.name }))}
          />
        ))}

      <EndpointForm
        // Remounted after each registration, which is how it empties itself.
        key={`new-${endpoints.length}`}
        id="register"
        title={t("hook.register.title")}
        description={t("hook.register.description")}
        submitLabel={t("hook.register")}
        subscribable={subscribable}
        busy={busy}
        onSubmit={(input) =>
          start(async () => {
            const result = await registerEndpoint(input);

            setError(result.error);
            setNotice(null);

            if (result.secret !== undefined) setSecret({ name: input.name, value: result.secret });
          })
        }
      />
    </div>
  );
}

/**
 * The secret, once.
 *
 * Its own panel, with the sentence that matters in bold, because the person
 * reading it has to act NOW — copy it into the receiver — and a secret shown
 * in a toast that fades is a secret somebody has to rotate tomorrow.
 */
function SecretOnce({ name, value, onDone }: { name: string; value: string; onDone: () => void }) {
  const t = useT();

  // Two pieces of the sentence are code. The translated sentence is cut at its
  // placeholders, so each language keeps its own word order around them.
  const parts = t("hook.secret.body").split(/\{(header|format)\}/);

  return (
    <Panel id="secret" title={t("hook.secret.title", { name })} tone="danger">
      <div className="space-y-3">
        <p className="text-body-sm text-n-700">
          <strong>{t("tok.shownOnce")}</strong>{" "}
          {parts.map((part, index) =>
            part === "header" ? (
              <code key={index} className="font-mono">
                X-WorkOS-Signature
              </code>
            ) : part === "format" ? (
              <code key={index} className="font-mono">
                t=&lt;time&gt;,v1=&lt;HMAC-SHA256 of “time.body”&gt;
              </code>
            ) : (
              part
            ),
          )}
        </p>

        <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
          {value}
        </code>

        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" size="sm" onClick={() => navigator.clipboard?.writeText(value)}>
            {t("hook.secret.copy")}
          </Button>
          <Button variant="ghost" size="sm" onClick={onDone}>
            {t("tok.saved")}
          </Button>
        </div>
      </div>
    </Panel>
  );
}

/**
 * Deleting asks first, in place, and says what it would break.
 *
 * While rules still send to it the control explains instead of arming: the API
 * would refuse, and a button whose only outcome is a refusal is a dead control.
 */
function DeleteEndpoint({
  endpoint,
  disabled,
  onConfirm,
}: {
  endpoint: WebhookEndpoint;
  disabled: boolean;
  onConfirm: () => void;
}) {
  const [armed, setArmed] = useState(false);
  const t = useT();

  if (endpoint.rules.length > 0) {
    // Words, not a greyed-out button: a disabled control with no reason is a
    // dead end, and the reason here is the whole point.
    return (
      <span className="self-center text-caption text-n-500">
        {t("hook.inUse")}
      </span>
    );
  }

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        {t("hook.delete")}
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
        {t("hook.deleteConfirm", { name: endpoint.name })}
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        {t("common.cancel")}
      </Button>
    </span>
  );
}

function EndpointForm({
  id,
  title,
  description,
  submitLabel,
  initial,
  subscribable,
  busy,
  onSubmit,
}: {
  id: string;
  title: string;
  description: string;
  submitLabel: string;
  initial?: WebhookEndpoint;
  subscribable: string[];
  busy: boolean;
  onSubmit: (input: { name: string; url: string; events: string[] }) => void;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [url, setUrl] = useState(initial?.url ?? "");
  const [events, setEvents] = useState<string[]>(initial?.events ?? []);
  const t = useT();

  const nameId = useId();
  const urlId = useId();

  // Why the button is off, in the words of what is missing.
  const blocker =
    name.trim() === ""
      ? t("hook.blocker.name")
      : !url.trim().startsWith("https://")
        ? t("hook.blocker.https")
        : null;

  return (
    <Panel
      id={id}
      title={title}
      description={description}
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            variant="primary"
            size="sm"
            disabled={busy || blocker !== null}
            onClick={() => onSubmit({ name: name.trim(), url: url.trim(), events })}
          >
            {busy ? t("common.saving") : submitLabel}
          </Button>

          {blocker !== null && (
            <p role="status" className="text-caption text-n-500">
              {blocker}
            </p>
          )}
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <Field id={nameId} label={t("projects.col.name")} hint={t("hook.name.hint")}>
          <input
            id={nameId}
            value={name}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            placeholder={t("hook.name.placeholder")}
            className={INPUT}
          />
        </Field>

        <Field id={urlId} label={t("hook.address")} hint={t("hook.address.hint")}>
          <input
            id={urlId}
            value={url}
            maxLength={2000}
            onChange={(event) => setUrl(event.target.value)}
            placeholder="https://"
            className={`${INPUT} font-mono`}
          />
        </Field>
      </div>

      {subscribable.length > 0 && (
        <fieldset className="mt-4 space-y-1.5">
          <legend className="text-caption font-medium text-n-700">{t("hook.every.legend")}</legend>
          <p className="text-caption text-n-500">
            {t("hook.every.body")}
          </p>
          {subscribable.map((event) => (
            <label key={event} className="flex items-center gap-2 text-body-sm text-n-900">
              <input
                type="checkbox"
                checked={events.includes(event)}
                onChange={() =>
                  setEvents((current) =>
                    current.includes(event) ? current.filter((e) => e !== event) : [...current, event],
                  )
                }
                className="size-4 accent-a-500"
              />
              {eventWords(event, t)}
              <code className="font-mono text-micro text-n-500">{event}</code>
            </label>
          ))}
        </fieldset>
      )}
    </Panel>
  );
}
