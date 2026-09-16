import { clsx } from "@/lib/clsx";
import type { ReactNode } from "react";

/**
 * Facts about one thing, in columns (ADR 0024).
 *
 * The pattern this replaces put one label and one value per ROW, so eight facts
 * about a person took eight lines and a screen's worth of vertical travel while
 * two thirds of the width sat empty. A definition list in a grid says the same
 * thing in two lines, and — because the labels line up — lets somebody compare
 * this person's page with the last one they had open.
 *
 * A real `<dl>`: the association between a label and its value is in the markup
 * rather than only in the layout, so it survives being read aloud.
 */
export function KeyValue({
  columns = 4,
  children,
}: {
  columns?: 2 | 3 | 4;
  children: ReactNode;
}) {
  return (
    <dl
      className={clsx(
        "grid grid-cols-2 gap-x-6 gap-y-3",
        columns === 3 && "sm:grid-cols-3",
        columns === 4 && "sm:grid-cols-4",
      )}
    >
      {children}
    </dl>
  );
}

export function KeyValueItem({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">{label}</dt>
      <dd className="mt-0.5 truncate text-body-sm text-n-900">{children}</dd>
    </div>
  );
}

/** An em dash that means "nothing recorded", not "zero". */
export function Unset() {
  return <span className="text-n-300">—</span>;
}
