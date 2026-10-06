"use client";

import { QRCodeSVG } from "qrcode.react";
import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import {
  beginEnrolment,
  confirmEnrolment,
  disableTwoFactor,
  regenerateRecoveryCodes,
} from "./actions";
import { useLocale, useT } from "@/i18n/I18nProvider";

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
  const [needsPassword, setNeedsPassword] = useState(false);
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  // Beginning asks for the password again when the sign-in is older than the
  // re-authentication window (ADR 0034); the prompt appears under the button
  // and starts enrolment itself once the password is accepted.
  const begin = () =>
    startAction(async () => {
      const result = await beginEnrolment();

      setNeedsPassword(result.needsPassword === true);
      setError(result.error);

      if (result.error === null && result.secret !== null) {
        setSecret(result.secret);
        setUri(result.uri);
        setStage("scanning");
      }
    });

  if (stage === "codes") {
    return (
      <Panel
        id="two-factor"
        title={t("tfa.codes.title")}
        description={t("tfa.codes.description")}
      >
        <ul className="grid grid-cols-2 gap-x-6 gap-y-1 rounded-lg border border-n-300 bg-n-25 p-4 font-mono text-body-sm text-n-900 sm:grid-cols-3">
          {codes.map((recovery) => (
            <li key={recovery}>{recovery}</li>
          ))}
        </ul>

        <p className="mt-3 max-w-prose text-caption text-n-500">
          {t("tfa.codes.keep")}
        </p>

        {/* Copy and download, because "this is the only time they are shown"
            and a screenshot are a bad pair — which is how the first person to
            use this screen lost theirs. */}
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              navigator.clipboard
                ?.writeText(codes.join("\n"))
                .then(() => toast({ tone: "done", message: t("tfa.codes.copied") }))
                .catch(() => setError(t("tfa.codes.copyFailed")));
            }}
          >
            {t("tfa.copy")}
          </Button>

          <Button variant="secondary" size="sm" onClick={() => download(codes, t)}>
            {t("tfa.download")}
          </Button>

          <Button variant="ghost" size="sm" onClick={() => setStage("idle")}>
            {t("tfa.saved")}
          </Button>
        </div>
      </Panel>
    );
  }

  if (stage === "scanning" && uri !== null) {
    return (
      <Panel
        id="two-factor"
        title={t("tfa.scan.title")}
        description={t("tfa.scan.description")}
      >
        {error && (
          <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
            {error}
          </p>
        )}

        <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
          {/* White stays white in dark mode: a QR code inverted is a QR code
              half the scanners in the world refuse. */}
          <div className="rounded-lg border border-n-300 bg-white p-3">
            <QRCodeSVG value={uri} size={160} marginSize={0} />
          </div>

          <div className="min-w-0 flex-1 space-y-3">
            <div>
              <p className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                {t("tfa.scan.key")}
              </p>
              <p className="mt-1 break-all font-mono text-body-sm text-n-900">{secret}</p>
            </div>

            <Field id="mfa-code" label={t("tfa.scan.code")} hint={t("tfa.scan.codeHint")}>
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
                    const result = await confirmEnrolment(formDataWith("code", code.trim()));

                    setError(result.error);

                    if (result.error === null) {
                      setCodes(result.codes);
                      setCode("");
                      setStage("codes");
                      toast({
                        tone: "done",
                        message: t("tfa.on.toast"),
                      });
                    }
                  })
                }
              >
                {busy ? t("tfa.checking") : t("tfa.turnOn")}
              </Button>

              <Button variant="ghost" size="sm" disabled={busy} onClick={() => setStage("idle")}>
                {t("common.cancel")}
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
        title={t("settings.twoFactor.label")}
        description={t("tfa.on.description")}
        actions={<Badge tone="success" icon="check">{t("tfa.badge.on")}</Badge>}
      >
        {error && (
          <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
            {error}
          </p>
        )}

        <div className="max-w-md space-y-3">
          <Field
            id="mfa-password"
            label={t("tfa.password")}
            hint={t("tfa.password.hint")}
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

          <div className="flex flex-wrap items-center gap-2">
            {/* The same password serves both, because both are acts a session
                alone should not be enough for. */}
            <Button
              variant="secondary"
              size="sm"
              disabled={busy || password === ""}
              onClick={() =>
                startAction(async () => {
                  const result = await regenerateRecoveryCodes(formDataWith("password", password));

                  setError(result.error);
                  setPassword("");

                  if (result.error === null) {
                    setCodes(result.codes);
                    setStage("codes");
                    toast({ tone: "removed", message: t("tfa.newCodes.toast") });
                  }
                })
              }
            >
              {busy ? t("feed.working") : t("tfa.newCodes")}
            </Button>

            <Button
              variant="destructive"
              size="sm"
              disabled={busy || password === ""}
              onClick={() =>
                startAction(async () => {
                  const result = await disableTwoFactor(formDataWith("password", password));

                  setError(result.error);
                  setPassword("");

                  if (result.error === null) {
                    toast({ tone: "removed", message: t("tfa.off.toast") });
                  }
                })
              }
            >
              {busy ? t("tfa.turningOff") : t("tfa.turnOff")}
            </Button>
          </div>
        </div>
      </Panel>
    );
  }

  return (
    <Panel
      id="two-factor"
      title={t("settings.twoFactor.label")}
      description={t("tfa.off.description")}
      actions={<Badge tone="neutral" icon="minus">{t("tfa.badge.off")}</Badge>}
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <p className="max-w-prose text-body-sm text-n-700">
        {t("tfa.off.why")}
      </p>

      <div className="mt-4">
        <Button
          variant="primary"
          size="sm"
          disabled={busy}
          onClick={begin}
        >
          {busy ? t("tfa.preparing") : t("tfa.setUp")}
        </Button>

        {needsPassword && (
          <ConfirmPassword
            action={t("tfa.confirm.setUp")}
            locale={locale}
            onConfirmed={() => {
              setNeedsPassword(false);
              begin();
            }}
          />
        )}
      </div>
    </Panel>
  );
}

/**
 * One field, as `FormData`.
 *
 * Server Actions take the value this way because Next.js prints plain
 * arguments to the development log and these two are a live one-time code and
 * a password (see `actions.ts`).
 */
function formDataWith(name: string, value: string): FormData {
  const form = new FormData();

  form.append(name, value);

  return form;
}

/**
 * The codes as a file, without a round trip.
 *
 * A blob URL built in the page: the codes are already here, and sending them
 * back to a server to be sent down again as an attachment would put them
 * through one more place they do not need to be.
 */
function download(codes: string[], t: ReturnType<typeof useT>) {
  const blob = new Blob(
    [`${t("tfa.file.heading")}\n\n${t("tfa.file.body")}\n\n${codes.join("\n")}\n`],
    { type: "text/plain" },
  );

  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = "work-os-recovery-codes.txt";
  link.click();

  URL.revokeObjectURL(url);
}
