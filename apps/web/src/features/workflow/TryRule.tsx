"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { previewRule, runRuleNow, type RulePreview } from "./actions";
import { describeAction } from "./describe";
import type { RuleAction } from "./types";
import { useT } from "@/i18n/I18nProvider";

/**
 * "Try this rule against one item" (ADR 0035).
 *
 * The question every automation system is asked first is **why didn't my rule
 * fire?** The run log answers it for rules that were triggered, and cannot
 * answer it for the rule somebody wrote five minutes ago — so what people do
 * instead is break a real work item to find out.
 *
 * Preview first, always, and the run button does not exist until a preview has
 * been seen. Sequencing rather than a confirmation dialogue: the preview IS the
 * confirmation, and it says something a dialogue cannot — exactly which actions
 * are about to happen to which item.
 */
export function TryRule({ ruleId }: { ruleId: string }) {
  const [reference, setReference] = useState("");
  const [preview, setPreview] = useState<RulePreview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();
  const t = useT();

  // Not matched, and part of the condition could not be evaluated: the honest
  // answer is "I cannot tell", and it is a different answer from "no".
  const inconclusive = preview !== null && !preview.matched && preview.unavailableFacts.length > 0;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-2">
        <Field
          id={`try-${ruleId}`}
          label={t("try.item")}
          hint={t("try.item.hint")}
        >
          <input
            id={`try-${ruleId}`}
            value={reference}
            disabled={busy}
            placeholder="ENG-142"
            onChange={(event) => {
              setReference(event.target.value);
              // A preview belongs to the item it was run against. Keeping it on
              // screen while somebody types a different reference is how a
              // person runs a rule against the wrong thing.
              setPreview(null);
            }}
            className={INPUT.replace("w-full", "w-40")}
          />
        </Field>

        <Button
          variant="secondary"
          size="sm"
          disabled={busy || reference.trim() === ""}
          onClick={() =>
            startAction(async () => {
              const result = await previewRule(ruleId, reference.trim());

              setError(result.error);
              setPreview(result.error === null ? result : null);
            })
          }
        >
          {busy ? t("tfa.checking") : t("try.ask")}
        </Button>
      </div>

      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {preview && (
        <div className="rounded-lg border border-n-300 bg-n-25 p-3">
          <div className="flex flex-wrap items-center gap-2">
            {/* Three verdicts, not two. A rule that asks about the moment and
                did not match has not been JUDGED — saying "conditions do not
                match" over an explanation that the condition could not be
                answered is two sentences that cannot both be true, and the
                confident one is the one people read (ADR 0035). */}
            {preview.matched ? (
              <Badge tone="success" icon="check">{t("try.match")}</Badge>
            ) : inconclusive ? (
              <Badge tone="warning" icon="alert">{t("try.cannotJudge")}</Badge>
            ) : (
              <Badge tone="neutral" icon="minus">{t("try.noMatch")}</Badge>
            )}
            <span className="text-body-sm text-n-700">
              {t("try.on", { reference: reference.trim().toUpperCase() })}
            </span>
          </div>

          {preview.unavailableFacts.length > 0 && (
            <p className="mt-2 max-w-prose text-caption text-s-active">
              {t("try.momentFacts", { facts: preview.unavailableFacts.join(", ") })}
              {preview.matched ? t("try.momentMatched") : t("try.momentNoMatch")}
            </p>
          )}

          {preview.matched && (
            <>
              <ul className="mt-2 space-y-1">
                {preview.actions.map((action, index) => {
                  const described = describeAction(action as unknown as RuleAction, t);

                  return (
                    <li key={index} className="text-body-sm text-n-900">
                      {described?.verb ?? String(action.type)}
                      {described && described.config.length > 0 && (
                        <span className="text-n-500"> · {described.config.join(" · ")}</span>
                      )}
                    </li>
                  );
                })}
              </ul>

              <div className="mt-3">
                <Button
                  variant="affirmative"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    startAction(async () => {
                      const result = await runRuleNow(ruleId, reference.trim());

                      setError(result.error);

                      if (result.error === null) {
                        setPreview(null);
                        toast({
                          tone: "done",
                          message: t("try.ran", {
                            reference: reference.trim().toUpperCase(),
                            outcome: result.outcome ?? t("try.done"),
                          }),
                        });
                      }
                    })
                  }
                >
                  {busy ? t("try.running") : t("try.runForReal")}
                </Button>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
