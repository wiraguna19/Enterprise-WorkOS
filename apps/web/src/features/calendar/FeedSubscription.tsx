"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
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
        {feed ? "Calendar subscription" : "Subscribe in your calendar"}
      </Button>
    );
  }

  return (
    <div className="w-full max-w-xl space-y-2 rounded-md border border-n-200 p-3 text-body-sm">
      {issued ? (
        <>
          <p className="text-n-700">
            Paste this into your calendar app. It is shown once — we store only a
            hash of it, so it cannot be retrieved later, only replaced.
          </p>
          {/* Selectable and wrapped rather than a copy button alone: a copy
              button that silently fails leaves the user with nothing. */}
          <code className="block break-all rounded-sm bg-n-50 p-2 font-mono text-caption text-n-900">
            {issued}
          </code>
          <Button size="sm" onClick={() => setIssued(null)}>
            Done
          </Button>
        </>
      ) : (
        <>
          <p className="text-n-700">
            {feed
              ? `A subscription exists${
                  feed.last_accessed_at ? "" : " but has never been fetched"
                }. Issuing a new URL immediately stops the old one working.`
              : "Your work and milestones, read-only, in Apple Calendar, Google Calendar, or Outlook."}
          </p>

          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="primary" disabled={busy} onClick={issue}>
              {busy ? "Working…" : feed ? "Replace URL" : "Create URL"}
            </Button>

            {feed && (
              <Button size="sm" variant="danger" disabled={busy} onClick={revoke}>
                Revoke
              </Button>
            )}

            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
              Close
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
