"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { clsx } from "@/lib/clsx";
import { setProjectPinned } from "./actions";

/**
 * Keep a project in your own sidebar, or stop (docs/08 §1, ADR 0044).
 *
 * In the directory, because that is where the choice is actually made: "pin
 * the 3–7 you work in" is a decision somebody takes while looking at the whole
 * list, not one they go into a project to make.
 *
 * A real `aria-pressed` button rather than a link or a checkbox — it toggles a
 * state on this row, and that is exactly what `aria-pressed` announces. The
 * label says which direction the press goes, because "Pin" on an already
 * pinned row is the control that gets pressed by mistake.
 */
export function PinToggle({
  projectKey,
  name,
  pinned,
}: {
  projectKey: string;
  name: string;
  pinned: boolean;
}) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [working, start] = useTransition();

  return (
    <span className="inline-flex items-center gap-1.5">
      <button
        type="button"
        aria-pressed={pinned}
        aria-label={pinned ? `Unpin ${name}` : `Pin ${name}`}
        title={pinned ? "In your sidebar" : "Keep in your sidebar"}
        disabled={working}
        onClick={() =>
          start(async () => {
            const result = await setProjectPinned(projectKey, !pinned);

            setError(result.error);

            // The sidebar lives in the LAYOUT, which this page cannot
            // revalidate by path alone from the client — the refresh is what
            // makes the pin appear where it was pinned to.
            if (result.error === null) router.refresh();
          })
        }
        className={clsx(
          "rounded-sm px-1 text-body leading-none transition-colors duration-[120ms]",
          pinned ? "text-a-500 hover:text-a-700" : "text-n-300 hover:text-n-500",
          working && "opacity-50",
        )}
      >
        {pinned ? "★" : "☆"}
      </button>

      {error !== null && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </span>
  );
}
