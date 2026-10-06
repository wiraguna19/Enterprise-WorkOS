"use client";

import { useEffect, useState } from "react";
import { AcceptInviteForm } from "./AcceptInviteForm";
import { previewInvitation } from "@/features/people/invitations";
import { useT } from "@/i18n/I18nProvider";

type State =
  | { stage: "loading" }
  | { stage: "invalid" }
  | { stage: "ready"; token: string; organization: string; email: string };

/**
 * Reads the token from the fragment and asks what it is for.
 *
 * In the browser because only the browser has the fragment. Asked once, on
 * mount; a person who edits the fragment by hand reloads the page.
 */
export function InvitePreview() {
  const [state, setState] = useState<State>({ stage: "loading" });
  const t = useT();

  useEffect(() => {
    const token = decodeURIComponent(window.location.hash.replace(/^#/, ""));
    const form = new FormData();

    form.append("token", token);

    let live = true;

    void previewInvitation(form).then((invitation) => {
      if (!live) return;

      setState(
        invitation === null
          ? { stage: "invalid" }
          : { stage: "ready", token, organization: invitation.organization, email: invitation.email },
      );
    });

    return () => {
      live = false;
    };
  }, []);

  // The address is bold inside the sentence; the sentence is cut at its
  // placeholder so each language keeps its own order around it.
  const [before, after] = t("join.invitedAs").split("{email}");

  return (
    <>
      <div className="mb-8">
        <div className="mb-6 flex items-center gap-2">
          <span className="flex size-6 items-center justify-center rounded-sm bg-n-900 text-micro font-bold text-n-0">
            W
          </span>
          <span className="text-body font-semibold text-n-900">Work OS</span>
        </div>

        {state.stage === "loading" && <p className="text-body text-n-500">{t("join.checking")}</p>}

        {state.stage === "invalid" && (
          <>
            <h1 className="text-h1 font-semibold text-n-900">{t("join.invalid.title")}</h1>
            <p className="mt-1 text-body text-n-500">{t("join.invalid.body")}</p>
          </>
        )}

        {state.stage === "ready" && (
          <>
            <h1 className="text-h1 font-semibold text-n-900">
              {t("join.title", { org: state.organization })}
            </h1>
            <p className="mt-1 text-body text-n-500">
              {before}
              <strong className="text-n-700">{state.email}</strong>
              {after}
            </p>
          </>
        )}
      </div>

      {state.stage === "ready" && <AcceptInviteForm token={state.token} />}
    </>
  );
}
