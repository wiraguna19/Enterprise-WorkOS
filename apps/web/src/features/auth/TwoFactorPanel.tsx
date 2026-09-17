"use client";

import { QRCodeSVG } from "qrcode.react";
import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { beginEnrolment, confirmEnrolment, disableTwoFactor } from "./actions";

/**
 * Enrolling, and un-enrolling, a second factor (ADR 0030).
 *
 * Four states, and each one is a different screen rather than a different
 * arrangement of the same one: off, scanning, saving the recovery codes, on.
 * A single form that grew fields as it went would put the QR code, the code
 * box and the recovery list on screen together, which is three instructions at
 * once for the one task in this product where following them in order matters.
 *
 * The recovery codes are shown ONCE, and the screen says so before it shows
 * them. A person who closes this panel without saving them has not lost their
 * account — they can turn the factor off with their password and start again —
 * and that is the sentence the copy has to carry without sounding harmless.
 */
type Stage = "idle" | "scanning" | "codes";

export function TwoFactorPanel({ enabled }: { enabled: boolean }) {
  const [stage, setStage] = useState<Stage>("idle");
  const [secret, setSecret] = useState<string | null>(null);
  const [uri, setUri] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[]>([]);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  if (stage === "codes") {
    return (
      <Panel
        id="two-factor"
        title="Save your recovery codes"
        description="Each one works once, in place of a code from your app. This is the only time they are shown."
      >
        <ul className="grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg border border-n-200 bg-n-25 p-4 font-mono text-body-sm text-n-900 sm:grid-cols-3">
          {codes.map((recovery) => (
            <li key={recovery}>{recovery}</li>
          ))}
        </ul>

        <p className="mt-3 max-w-prose text-caption text-n-500">
          Keep them somewhere that is not the phone running your authenticator app — the point of
          them is the day that phone is gone. If you lose them, turn two-factor off with your
          password and set it up again.
        </p>

        <div className="mt-4">
          <Button variant="secondary" size="sm" onClick={() => setStage("idle")}>
            I have saved them
          </Button>
        </div>
      </Panel>
    );
  }

  if (stage === "scanning" && uri !== null) {
    return (
      <Panel
        id="two-factor"
        title="Scan this with your authenticator app"
        description="Then type the six digits it shows, to prove the app and this account agree."
      >
        {error && (
          <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
            {error}
          </p>
        )}

        <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
          {/* White stays white in dark mode: a QR code inverted is a QR code
              half the scanners in the world refuse. */}
          <div className="rounded-lg border border-n-200 bg-white p-3">
            <QRCodeSVG value={uri} size={160} marginSize={0} />
          </div>

          <div className="min-w-0 flex-1 space-y-3">
            <div>
              <p className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                Or type the key
              </p>
              <p className="mt-1 break-all font-mono text-body-sm text-n-900">{secret}</p>
            </div>

            <Field id="mfa-code" label="Code from the app" hint="Six digits.">
              <input
                id="mfa-code"
                value={code}
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={7}
                disabled={busy}
                onChange={(event) => setCode(event.target.value)}
                className={INPUT.replace("w-full", "w-40")}
              />
            </Field>

            <div className="flex items-center gap-2">
              <Button
                variant="affirmative"
                size="sm"
                disabled={busy || code.trim() === ""}
                onClick={() =>
                  startAction(async () => {
                    const result = await confirmEnrolment(code.trim());

                    setError(result.error);

                    if (result.error === null) {
                      setCodes(result.codes);
                      setCode("");
                      setStage("codes");
                      toast({
                        tone: "done",
                        message: "Two-factor is on. Other devices signed in as you were signed out.",
                      });
                    }
                  })
                }
              >
                {busy ? "Checking…" : "Turn on"}
              </Button>

              <Button variant="ghost" size="sm" disabled={busy} onClick={() => setStage("idle")}>
                Cancel
              </Button>
            </div>
          </div>
        </div>
      </Panel>
    );
  }

  if (enabled) {
    return (
      <Panel
        id="two-factor"
        title="Two-factor authentication"
        description="Signing in asks for a code from your authenticator app as well as your password."
        actions={<Badge tone="success" icon="check">on</Badge>}
      >
        {error && (
          <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
            {error}
          </p>
        )}

        <div className="max-w-md space-y-3">
          <Field
            id="mfa-password"
            label="Password"
            hint="Turning the factor off needs something you know, not only the session you are in."
          >
            <input
              id="mfa-password"
              type="password"
              value={password}
              autoComplete="current-password"
              disabled={busy}
              onChange={(event) => setPassword(event.target.value)}
              className={INPUT.replace("w-full", "w-64")}
            />
          </Field>

          <Button
            variant="destructive"
            size="sm"
            disabled={busy || password === ""}
            onClick={() =>
              startAction(async () => {
                const result = await disableTwoFactor(password);

                setError(result.error);
                setPassword("");

                if (result.error === null) {
                  toast({ tone: "removed", message: "Two-factor is off." });
                }
              })
            }
          >
            {busy ? "Turning off…" : "Turn off"}
          </Button>
        </div>
      </Panel>
    );
  }

  return (
    <Panel
      id="two-factor"
      title="Two-factor authentication"
      description="A code from an app on your phone, on top of your password."
      actions={<Badge tone="neutral" icon="minus">off</Badge>}
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <p className="max-w-prose text-body-sm text-n-700">
        A stolen password is enough to sign in as you. A stolen password and a code that changes
        every thirty seconds is not. Turning this on signs out every other device signed in as you.
      </p>

      <div className="mt-4">
        <Button
          variant="primary"
          size="sm"
          disabled={busy}
          onClick={() =>
            startAction(async () => {
              const result = await beginEnrolment();

              setError(result.error);

              if (result.error === null) {
                setSecret(result.secret);
                setUri(result.uri);
                setStage("scanning");
              }
            })
          }
        >
          {busy ? "Preparing…" : "Set up two-factor"}
        </Button>
      </div>
    </Panel>
  );
}
