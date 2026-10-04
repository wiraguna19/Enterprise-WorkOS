"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useT } from "@/i18n/I18nProvider";
import { createFeed, revokeFeed } from "./actions";
import type { FeedStatus } from "./types";

/**
 * Subscribing an external calendar (docs/06 §1).
 *
 * The URL is the credential — a calendar client cannot present a token — so it
 * is shown once, at creation, and never again: only its digest is stored. This
 * component is built around that fact rather than around hiding it. It says so
 * before issuing one, keeps it on screen until dismissed, and offers revoke
 * rather than a "show URL" that could not work.
 */
export function FeedSubscription({ feed }: { feed: FeedStatus }) {
  const t = useT();
  const [issued, setIssued] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [busy, startTransition] = useTransition();

  const issue = () =>
    startTransition(async () => {
      const result = await createFeed();

      setError(result.error);
      setIssued(result.url);
    });

  const revoke = () =>
    startTransition(async () => {
      const result = await revokeFeed();

      setError(result.error);

      if (result.error === null) setIssued(null);
    });

  if (!open) {
    return (
      <Button size="sm" variant="ghost" onClick={() => setOpen(true)}>
        {feed ? t("feed.subscription") : t("feed.subscribe")}
      </Button>
    );
  }

  return (
    // `n-300` and the panel radius: this is a container, and it was the one
    // container in the product still wearing the lighter border (ADR 0024).
    <div className="w-full max-w-xl space-y-2 rounded-lg border border-n-300 p-3 text-body-sm">
      {issued ? (
        <>
          <p className="text-n-700">
            {t("feed.paste")}
          </p>
          {/* Selectable and wrapped rather than a copy button alone: a copy
              button that silently fails leaves the user with nothing. */}
          <code className="block break-all rounded-sm bg-n-50 p-2 font-mono text-caption text-n-900">
            {issued}
          </code>
          <Button size="sm" onClick={() => setIssued(null)}>
            {t("toast.done")}
          </Button>
        </>
      ) : (
        <>
          <p className="text-n-700">
            {feed
              ? t(feed.last_accessed_at ? "feed.exists" : "feed.existsUnused")
              : t("feed.intro")}
          </p>

          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="primary" disabled={busy} onClick={issue}>
              {busy ? t("feed.working") : feed ? t("feed.replace") : t("feed.create")}
            </Button>

            {feed && (
              <Button size="sm" variant="danger" disabled={busy} onClick={revoke}>
                {t("feed.revoke")}
              </Button>
            )}

            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
              {t("tpl.close")}
            </Button>
          </div>
        </>
      )}

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
        </p>
      )}
    </div>
  );
}
