"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { clsx } from "@/lib/clsx";
import type { ReactNode } from "react";

/**
 * Saying that something happened (ADR 0025).
 *
 * Until this existed, failure spoke and success was silent: pressing Grant, or
 * Revoke, or End produced a sentence only when it went wrong. The product's
 * whole answer to "did that work?" was "the list looks different now" — which
 * is a real answer when the list is on screen, and no answer at all when the
 * effect is somewhere else.
 *
 * **It is deliberately not a toast for everything.** A confirmation for every
 * keystroke-level save — the notification preference toggles, an inline
 * rename — is noise that teaches people to look away from the corner where the
 * important ones appear. ADR 0025 sets the rule: if the effect is visible where
 * you are standing, the screen IS the confirmation; a toast is for an effect
 * that happens out of sight, or for an act consequential enough to deserve
 * saying out loud.
 *
 * Errors keep appearing inline, next to the control that failed, because that
 * is where somebody is looking and because an error that scrolls away with a
 * timer is an error nobody can act on.
 */
/**
 * Two tones, and the distinction is CONSEQUENCE — the same rule the buttons
 * follow (ADR 0024, ADR 0025).
 *
 * Both are successes: a toast never reports a failure, because failures stay
 * inline beside the control that produced them. So colouring by verb would be
 * colour as decoration. What is worth distinguishing is that "Granted" and
 * "Erased" are not the same kind of news — one gives something, the other takes
 * it away for good, and an identical neutral box under-reports the second.
 *
 * The BOX stays neutral in both cases. A red-filled toast reads as an error at
 * a glance, and this component never has one to report; only the mark in front
 * of the sentence carries the tone.
 */
type Tone = "done" | "removed";

type Toast = { id: number; tone: Tone; message: string };

/** A mark, not a colour alone (docs/09 §5): a person who cannot tell the two
 *  hues apart still gets the difference. */
const MARK = {
  done: { glyph: "✓", className: "text-a-700", label: "Done" },
  removed: { glyph: "−", className: "text-s-danger", label: "Removed" },
} as const;

const ToastContext = createContext<((toast: { tone?: Tone; message: string }) => void) | null>(
  null,
);

/** How long a message stays. Long enough to read twice, short enough not to
 *  become furniture. */
const LIFETIME_MS = 5000;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const push = useCallback(({ tone = "done", message }: { tone?: Tone; message: string }) => {
    setToasts((current) => [...current, { id: Date.now() + current.length, tone, message }]);
  }, []);

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  return (
    <ToastContext.Provider value={push}>
      {children}

      {/* `polite`, not `assertive`: these announce something that has already
          happened successfully, and interrupting a screen reader mid-sentence
          to say "Saved" is the assistive-technology version of a popup. */}
      <div
        aria-live="polite"
        aria-relevant="additions"
        className="pointer-events-none fixed inset-x-0 bottom-16 z-40 flex flex-col items-center gap-2 px-4 md:bottom-4 md:right-4 md:left-auto md:items-end md:px-0"
      >
        {toasts.map((toast) => (
          <ToastRow key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

function ToastRow({ toast, onDismiss }: { toast: Toast; onDismiss: () => void }) {
  useEffect(() => {
    const timer = setTimeout(onDismiss, LIFETIME_MS);

    return () => clearTimeout(timer);
  }, [onDismiss]);

  return (
    <div
      className="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-xl border border-n-300 bg-n-0 px-3 py-2.5 text-n-900 shadow-e2"
    >
      <span className={clsx("mt-px shrink-0 text-body-sm leading-5", MARK[toast.tone].className)}>
        {MARK[toast.tone].glyph}
        <span className="sr-only">{MARK[toast.tone].label}:</span>
      </span>

      <p className="min-w-0 flex-1 text-body-sm">{toast.message}</p>

      <button
        type="button"
        onClick={onDismiss}
        className="-m-1 shrink-0 rounded-md p-1 text-n-500 hover:bg-n-50 hover:text-n-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-a-500/40"
      >
        {/* A close control, because five seconds is a guess about somebody
            else's reading speed. */}
        <span className="block text-micro leading-none">✕</span>
        <span className="sr-only">Dismiss</span>
      </button>
    </div>
  );
}

/**
 * Say something happened.
 *
 * Returns a no-op outside the provider rather than throwing: a component that
 * renders in a test harness or a story without the shell should not crash over
 * a confirmation message.
 */
export function useToast(): (toast: { tone?: Tone; message: string }) => void {
  return useContext(ToastContext) ?? (() => {});
}
