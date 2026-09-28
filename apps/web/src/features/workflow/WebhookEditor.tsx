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
import { STATUS_WORDS, type WebhookEndpoint } from "./webhooks";

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
  showingDeliveriesFor,
}: {
  endpoints: WebhookEndpoint[];
  /** The endpoint whose deliveries the page is listing, if any. */
  showingDeliveriesFor?: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [secret, setSecret] = useState<{ name: string; value: string } | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [busy, start] = useTransition();

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
        title="Endpoints"
        description={
          endpoints.length === 0
            ? "None yet. An endpoint registered here can be chosen by an automation rule's “Send a webhook” action."
            : `${endpoints.filter((endpoint) => endpoint.is_active).length} of ${endpoints.length} switched on.`
        }
        bleed
      >
        {endpoints.length > 0 && (
          <DataTable caption="Webhook endpoints">
            <THead>
              <Tr>
                <Th>Name</Th>
                <Th>Used by</Th>
                <Th align="right">Actions</Th>
              </Tr>
            </THead>
            <TBody>
              {endpoints.map((endpoint) => (
                <Tr key={endpoint.id}>
                  <Td>
                    <span className="font-medium">{endpoint.name}</span>
                    {!endpoint.is_active && (
                      <span className="ml-2">
                        <Badge tone="neutral">switched off</Badge>
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
                    {endpoint.rules.length === 0 ? "No rules" : endpoint.rules.join(", ")}
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
                                : `Test sent to ${endpoint.name}: ${STATUS_WORDS[result.delivery.status].toLowerCase()}${
                                    result.delivery.last_error ? ` — ${result.delivery.last_error}` : "."
                                  }`,
                            );
                          })
                        }
                      >
                        Send a test
                      </Button>

                      {/* An anchor: it GOES to the same page, showing this
                          endpoint's deliveries, and survives a reload. */}
                      <Link
                        href={`/settings/webhooks?endpoint=${endpoint.id}#deliveries`}
                        aria-current={showingDeliveriesFor === endpoint.id ? "page" : undefined}
                        className="inline-flex items-center rounded-md border border-n-300 px-2 py-1 text-caption text-n-700 hover:bg-n-50"
                      >
                        Deliveries
                      </Link>

                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy}
                        onClick={() => setEditing(editing === endpoint.id ? null : endpoint.id)}
                      >
                        {editing === endpoint.id ? "Close" : "Edit"}
                      </Button>

                      {endpoint.is_active ? (
                        <Button
                          size="sm"
                          variant="destructive"
                          disabled={busy}
                          onClick={() => run(() => setEndpointActive(endpoint.id, false), `${endpoint.name} is switched off.`)}
                        >
                          Switch off
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="affirmative"
                          disabled={busy}
                          onClick={() => run(() => setEndpointActive(endpoint.id, true), `${endpoint.name} is switched on.`)}
                        >
                          Switch on
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
                        New secret
                      </Button>

                      <DeleteEndpoint
                        endpoint={endpoint}
                        disabled={busy}
                        onConfirm={() => run(() => deleteEndpoint(endpoint.id), `${endpoint.name} is deleted.`)}
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
            title={`Edit ${endpoint.name}`}
            description="Changing the address keeps the secret. Rotating it is a separate control, so a receiver that moved does not also lose its key."
            submitLabel="Save"
            initial={endpoint}
            busy={busy}
            onSubmit={(input) => run(() => saveEndpoint(endpoint.id, input), `${input.name} is saved.`)}
          />
        ))}

      <EndpointForm
        // Remounted after each registration, which is how it empties itself.
        key={`new-${endpoints.length}`}
        id="register"
        title="Register an endpoint"
        description="An https address outside this network. Private, loopback and cloud-metadata addresses are refused — here, and again before every send."
        submitLabel="Register"
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
  return (
    <Panel id="secret" title={`Signing secret for ${name}`} tone="danger">
      <div className="space-y-3">
        <p className="text-body-sm text-n-700">
          <strong>It is shown once.</strong> Put it in the receiver now: it verifies the{" "}
          <code className="font-mono">X-WorkOS-Signature</code> header, which is{" "}
          <code className="font-mono">t=&lt;time&gt;,v1=&lt;HMAC-SHA256 of “time.body”&gt;</code>. If
          it is lost, make a new one.
        </p>

        <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
          {value}
        </code>

        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" size="sm" onClick={() => navigator.clipboard?.writeText(value)}>
            Copy the secret
          </Button>
          <Button variant="ghost" size="sm" onClick={onDone}>
            I have saved it
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

  if (endpoint.rules.length > 0) {
    // Words, not a greyed-out button: a disabled control with no reason is a
    // dead end, and the reason here is the whole point.
    return (
      <span className="self-center text-caption text-n-500">
        In use by rules — switch it off instead
      </span>
    );
  }

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        Delete
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
        Delete {endpoint.name} and its delivery history
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        Cancel
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
  busy,
  onSubmit,
}: {
  id: string;
  title: string;
  description: string;
  submitLabel: string;
  initial?: WebhookEndpoint;
  busy: boolean;
  onSubmit: (input: { name: string; url: string }) => void;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [url, setUrl] = useState(initial?.url ?? "");

  const nameId = useId();
  const urlId = useId();

  // Why the button is off, in the words of what is missing.
  const blocker =
    name.trim() === ""
      ? "Give it a name — it is what a rule author picks from."
      : !url.trim().startsWith("https://")
        ? "The address has to start with https://."
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
            onClick={() => onSubmit({ name: name.trim(), url: url.trim() })}
          >
            {busy ? "Saving…" : submitLabel}
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
        <Field id={nameId} label="Name" hint="What the rule builder shows. Unique here.">
          <input
            id={nameId}
            value={name}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            placeholder="e.g. Ops channel"
            className={INPUT}
          />
        </Field>

        <Field id={urlId} label="Address" hint="https only. Redirects are not followed.">
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
    </Panel>
  );
}
