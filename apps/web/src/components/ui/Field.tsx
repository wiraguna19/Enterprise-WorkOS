import type { ReactNode } from "react";

/**
 * A labelled form control, and the one class string every input in this product
 * wears.
 *
 * Extracted from the New project form when the second and third forms arrived.
 * Copying it would have been quicker and is the shape this codebase keeps
 * paying for: two lists that must agree eventually will not, and a focus ring
 * that matches on three screens and not the fourth is the version of that bug
 * nobody files.
 *
 * The label is a real `<label htmlFor>` rather than a styled span, which is
 * what lets a test say `getByLabel("Name")` and a screen reader say it too.
 */
export const INPUT =
  "w-full rounded-md border border-n-200 bg-n-0 px-2 py-1.5 text-body-sm text-n-900 placeholder:text-n-400 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30";

export function Field({
  id,
  label,
  hint,
  children,
}: {
  id: string;
  label: string;
  /** One line saying what the field means, where the label cannot. */
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label
        htmlFor={id}
        className="mb-0.5 block text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
      >
        {label}
      </label>
      {children}
      {hint && <p className="mt-0.5 text-caption text-n-500">{hint}</p>}
    </div>
  );
}
