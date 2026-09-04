"use client";

import { useEffect, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { clsx } from "@/lib/clsx";
import { downloadUrl, requestExport } from "./actions";
import type { ReportExport } from "./types";

/** Thirty seconds of watching. Past that, say so instead of spinning. */
const MAX_ATTEMPTS = 20;

/**
 * Asking for a file, and watching it be built (ADR 0011).
 *
 * The whole export slice shipped in Phase 6 — four reports, a queued job that
 * runs with the requester's own visibility, presigned URLs, expiry and a
 * pruning command — with **nothing in the interface that could ask for one**.
 * Every test passed. This is the button.
 *
 * The waiting is the design, not a limitation. An export is a row, not a
 * response: the request answers 202 and a worker builds the file afterwards,
 * because building it inside the request gives the customer with the most data
 * the worst behaviour. So the panel shows the row from the moment it exists and
 * says which state it is in.
 */
export function ExportPanel({
  reportKey,
  parameters,
  exports,
  formats,
}: {
  reportKey: string;
  parameters: Record<string, string>;
  exports: ReportExport[];
  formats: string[];
}) {
  const [format, setFormat] = useState(formats[0] ?? "csv");
  const [error, setError] = useState<string | null>(null);
  const [busy, startRequest] = useTransition();
  const router = useRouter();

  const mine = exports.filter((row) => row.report === reportKey);
  const waiting = mine.some((row) => row.status === "pending");

  // Watched only while something is actually pending, and given up on rather
  // than polled forever: a queue that is not running is a finding the reader
  // should be told about, not a spinner that turns until they leave.
  //
  // State rather than a ref, and the difference matters here: the giving-up
  // message is rendered FROM this count, and a ref changes without re-rendering
  // — the panel would poll thirty times and go on saying "Building…".
  const [attempts, setAttempts] = useState(0);

  useEffect(() => {
    if (!waiting || attempts >= MAX_ATTEMPTS) return;

    const timer = setTimeout(() => {
      setAttempts((count) => count + 1);
      router.refresh();
    }, 1500);

    return () => clearTimeout(timer);
  }, [waiting, attempts, router]);

  // Deliberately not reset when the wait ends: the only thing that starts a
  // wait from this panel is the button, and the button resets it. An effect
  // that reset it on every settled render would set state during render for no
  // behaviour anyone can observe.
  const stalled = waiting && attempts >= MAX_ATTEMPTS;

  return (
    <section aria-labelledby="export-heading" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="export-heading" className="text-body font-semibold text-n-900">
            Export
          </h2>
          <p className="text-caption text-n-500">
            Built from the same window this page is showing, with what you can see.
          </p>
        </div>

        <div className="flex items-end gap-2">
          <label className="flex flex-col gap-1 text-caption text-n-700">
            Format
            <select
              value={format}
              onChange={(event) => setFormat(event.target.value)}
              className="rounded-sm border border-n-200 px-2 py-1.5 text-body-sm text-n-900 focus:border-a-500 focus:outline-2 focus:outline-offset-1 focus:outline-a-500"
            >
              {/* From the server. A list kept here would offer formats that do
                  not exist yet, or hide ones that do. */}
              {formats.map((option) => (
                <option key={option} value={option}>
                  {option.toUpperCase()}
                </option>
              ))}
            </select>
          </label>

          <Button
            type="button"
            variant="primary"
            disabled={busy}
            onClick={() => {
              setError(null);

              startRequest(async () => {
                const result = await requestExport(reportKey, format, parameters);

                setError(result.error);
                setAttempts(0);
                router.refresh();
              });
            }}
          >
            {busy ? "Requesting…" : "Export"}
          </Button>
        </div>
      </div>

      {error && (
        <p role="alert" className="rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-caption text-s-danger">
          {error}
        </p>
      )}

      {stalled && (
        <p role="status" className="rounded-sm border border-s-active/30 bg-s-active/5 px-3 py-2 text-caption text-n-700">
          This export has been building for a while. Exports are made by a
          background worker — if none is running, the file will never arrive.
        </p>
      )}

      {mine.length > 0 && (
        <ul className="divide-y divide-n-100 rounded-sm border border-n-200">
          {mine.map((row) => (
            <ExportRow key={row.id} row={row} />
          ))}
        </ul>
      )}
    </section>
  );
}

function ExportRow({ row }: { row: ReportExport }) {
  const [error, setError] = useState<string | null>(null);
  const [opening, startOpening] = useTransition();

  return (
    <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-body-sm">
      <span className="font-mono text-micro uppercase text-n-500">{row.format}</span>

      <span className="text-n-700">
        {describe(row)}
      </span>

      {/* What the file does NOT contain, next to what it does. The count is a
          fact about the report and the rows are a fact about the reader; an
          export is the one place they cannot be compared against a screen. */}
      {row.hidden_count !== null && row.hidden_count > 0 && (
        <span className="text-caption text-n-500">
          {row.hidden_count} row{row.hidden_count === 1 ? "" : "s"} you cannot see were left out
        </span>
      )}

      <span className="ml-auto flex items-center gap-3">
        {row.status === "ready" && (
          <button
            type="button"
            disabled={opening}
            onClick={() => {
              setError(null);

              startOpening(async () => {
                // Asked for at the click. The URL lives five minutes, so one
                // rendered into this list would expire while the page sat open.
                const result = await downloadUrl(row.id);

                if (result.url) {
                  window.location.href = result.url;

                  return;
                }

                setError(result.error);
              });
            }}
            className="text-a-500 underline underline-offset-2 hover:text-a-700"
          >
            {opening ? "Opening…" : "Download"}
          </button>
        )}

        <span
          className={clsx(
            "text-caption",
            row.status === "failed" ? "text-s-danger" : "text-n-500",
          )}
        >
          {row.status}
        </span>
      </span>

      {error && (
        <p role="alert" className="basis-full text-caption text-s-danger">
          {error}
        </p>
      )}
    </li>
  );
}

/**
 * What this row is, in a sentence.
 *
 * A failure says WHY, from the row rather than from a log: "my export failed"
 * is asked by the person who requested it, and the reason was stored precisely
 * so they can be answered.
 */
function describe(row: ReportExport): string {
  switch (row.status) {
    case "pending":
      return "Building…";
    case "ready":
      return row.row_count === null
        ? (row.filename ?? "Ready")
        : `${row.row_count} row${row.row_count === 1 ? "" : "s"}`;
    case "failed":
      return row.failure_reason ?? "This export failed.";
    case "expired":
      return "Expired — the file has been deleted.";
    default:
      return row.status;
  }
}
