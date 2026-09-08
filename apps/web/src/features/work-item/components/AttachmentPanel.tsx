"use client";

import { useRef, useState, useTransition } from "react";
import { attachUploadedFile, attachmentUrl, reserveUpload } from "../actions";

export type Attachment = {
  id: string;
  attached_at: string;
  attached_by: string | null;
  file: {
    id: string;
    name: string;
    size_bytes: number;
    mime_type: string;
    available: boolean;
    scan_status: string | null;
  };
};

/**
 * Attachments (docs/08 §4, docs/11 §4 flow 6).
 *
 * The upload is three steps and only the middle one leaves this app:
 *
 *   1. `reserveUpload` — a Server Action. The API decides whether this person
 *      may upload and whether the type and size are acceptable, records the
 *      file, and signs a URL for one object.
 *   2. The browser PUTs the bytes to that URL. This is the only request in the
 *      product that goes anywhere other than our own API, and it is the point
 *      of the design: a 200 MB file routed through a Server Action would sit
 *      in a Node process's memory and then a PHP worker's, to end up where the
 *      signed URL puts it directly (docs/05 §6).
 *   3. `attachUploadedFile` — the bytes are there, and this item now has them.
 *
 * Step 2 is the one that can fail in ways this app cannot see: the browser
 * talks to storage, and a CORS rule or an expired URL produces a failure with
 * no message worth showing. So the status is reported per step, and a failure
 * says WHICH step — "the file did not reach storage" and "storage has it but
 * this item does not" are different problems with different fixes.
 *
 * Nothing is optimistic: the list re-renders from the server after the attach
 * (ADR 0012). A row appearing before the server agrees would be a row that can
 * vanish on the next reload.
 */
export function AttachmentPanel({
  reference,
  attachments,
  canAttach,
  timeZone,
}: {
  reference: string;
  attachments: Attachment[];
  canAttach: boolean;
  timeZone: string;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, startTransition] = useTransition();

  const upload = (file: File) =>
    startTransition(async () => {
      setError(null);
      setStatus(`Reserving a place for ${file.name}…`);

      // A file the browser cannot type is sent as an empty string, and the API
      // answers 422 with the list of what it accepts. Better its refusal than
      // a guess here about what "" means.
      const reservation = await reserveUpload(file.name, file.type, file.size);

      if (reservation.error !== null) {
        setStatus(null);
        setError(reservation.error);

        return;
      }

      setStatus(`Uploading ${file.name}…`);

      const put = await fetch(reservation.uploadUrl, {
        method: "PUT",
        headers: { "Content-Type": file.type },
        body: file,
      }).catch(() => null);

      if (put === null || !put.ok) {
        setStatus(null);
        setError(
          "The file did not reach storage. Nothing has been attached, so this can be "
            + "retried — the reserved place expires on its own.",
        );

        return;
      }

      setStatus("Attaching…");

      const attached = await attachUploadedFile(reference, reservation.fileId);

      setStatus(null);
      setError(attached.error);

      if (attached.error === null && input.current !== null) {
        // Cleared so the same file can be picked again: a file input keeps its
        // value, and re-selecting an identical path fires no change event.
        input.current.value = "";
      }
    });

  return (
    <div className="space-y-3">
      {attachments.length === 0 ? (
        <p className="text-body text-n-500">Nothing attached yet.</p>
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {attachments.map((attachment) => (
            <AttachmentRow key={attachment.id} attachment={attachment} timeZone={timeZone} />
          ))}
        </ul>
      )}

      {canAttach ? (
        <div className="flex flex-wrap items-center gap-2">
          <input
            ref={input}
            type="file"
            aria-label="Attach a file"
            disabled={busy}
            onChange={(event) => {
              const file = event.target.files?.[0];

              if (file !== undefined) upload(file);
            }}
            className="text-caption text-n-700 file:mr-2 file:rounded-sm file:border file:border-n-200 file:bg-n-0 file:px-2 file:py-1 file:text-body-sm file:text-n-700 hover:file:bg-n-50"
          />

          {status !== null && <span className="text-caption text-n-500">{status}</span>}

          {error !== null && (
            <p role="alert" className="w-full text-caption text-s-danger">
              {error}
            </p>
          )}
        </div>
      ) : (
        <p className="text-caption text-n-500">
          You can read these, but not add to them.
        </p>
      )}
    </div>
  );
}

function AttachmentRow({
  attachment,
  timeZone,
}: {
  attachment: Attachment;
  timeZone: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [opening, startTransition] = useTransition();

  const open = () =>
    startTransition(async () => {
      const result = await attachmentUrl(attachment.file.id);

      setError(result.error);

      // A new tab rather than a navigation: the URL is a redirect to storage,
      // and coming "back" from a download leaves the item page where it was.
      if (result.url !== null) window.open(result.url, "_blank", "noopener,noreferrer");
    });

  return (
    <li className="flex items-baseline gap-3 py-2">
      <span className="min-w-0 flex-1">
        {attachment.file.available ? (
          <button
            type="button"
            onClick={open}
            disabled={opening}
            className="text-body text-a-500 underline-offset-2 hover:underline disabled:text-n-300"
          >
            {attachment.file.name}
          </button>
        ) : (
          // Named, and unopenable, and told why. Hiding it would read as a
          // failed upload to the person who just made it.
          <span className="text-body text-n-500">
            {attachment.file.name}
            <span className="ml-1.5 text-caption text-s-active">
              {attachment.file.scan_status === "pending" ? "· being checked" : "· not available"}
            </span>
          </span>
        )}

        <span className="ml-2 text-caption text-n-500">{size(attachment.file.size_bytes)}</span>

        {error !== null && (
          <span role="alert" className="ml-2 text-caption text-s-danger">
            {error}
          </span>
        )}
      </span>

      <span className="shrink-0 text-caption text-n-500">
        {attachment.attached_by ?? "Someone"} ·{" "}
        {new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", timeZone })
          .format(new Date(attachment.attached_at))}
      </span>
    </li>
  );
}

/** Bytes, in the units a person reads. */
function size(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
