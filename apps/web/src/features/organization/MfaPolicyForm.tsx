"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setMfaPolicy } from "./actions";

/**
 * Requiring a second factor of everybody in this organization (ADR 0033).
 *
 * The screen has to carry one idea that the switch itself cannot: **nobody is
 * signed out and nobody is locked out.** A person without a factor keeps their
 * session and can do four things with it — say who they are, sign out, start
 * enrolling, finish. Everything else waits until they have.
 *
 * The number of people that applies to is named before the button is pressed,
 * and again after, because it is the difference between a setting and a
 * consequence — and here the consequence lands on other people's afternoons.
 */
export function MfaPolicyForm({
  required,
  peopleWithout,
  editable,
}: {
  required: boolean;
  peopleWithout: number;
  editable: boolean;
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  return (
    <Panel
      id="mfa-policy"
      title="Two-factor authentication"
      description="Whether everybody here has to prove a second factor before they can work."
      actions={
        required ? (
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
          {required
            ? "Everybody here signs in with a code as well as a password. Anybody who has not set one up yet is held at the enrolment screen until they do — signed in, but unable to do anything else."
            : "People may turn on a second factor for themselves. Requiring it means nobody here can work without one."}
        </p>

        {!required && peopleWithout > 0 && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {peopleWithout} {peopleWithout === 1 ? "person has" : "people have"} no second factor
            yet. Turning this on holds {peopleWithout === 1 ? "them" : "them"} at the enrolment
            screen on their next request — including you, if that is you. Nobody is signed out.
          </p>
        )}

        {editable && (
          <Button
            variant={required ? "destructive" : "affirmative"}
            size="sm"
            disabled={busy}
            onClick={() =>
              startAction(async () => {
                const result = await setMfaPolicy(!required);

                setError(result.error);

                if (result.error === null) {
                  toast({
                    tone: required ? "removed" : "done",
                    message: required
                      ? "Two-factor is optional here again."
                      : result.confined > 0
                        ? `Two-factor is required. ${result.confined} ${result.confined === 1 ? "person has" : "people have"} yet to enrol.`
                        : "Two-factor is required. Everybody here already has one.",
                  });
                }
              })
            }
          >
            {busy ? "Saving…" : required ? "Stop requiring it" : "Require it"}
          </Button>
        )}
      </div>
    </Panel>
  );
}
