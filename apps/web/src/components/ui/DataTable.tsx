import { clsx } from "@/lib/clsx";
import type { ReactNode, ThHTMLAttributes, TdHTMLAttributes } from "react";

/**
 * A real table: columns, a header row, and the density tokens (ADR 0024).
 *
 * Every list in this product was a stack of `<li>`s with values piled
 * vertically inside each one, which is why screens looked empty and cluttered
 * at the same time — three facts about one row occupying three lines and half
 * the width of a 1900px display. Columns put those three facts side by side and
 * let the eye compare DOWN a column, which is the entire reason tables exist.
 *
 * `--row-height` and `--cell-padding-*` have been in `globals.css` since Phase
 * 1, with a `[data-density="comfortable"]` override, and nothing had ever read
 * them. These cells do, so the density switch docs/09 §4 describes is real for
 * the first time.
 *
 * A `<table>` and not a grid of divs: screen readers announce a column header
 * with each cell, and a keyboard user can move by column. A div grid has to
 * rebuild all of that with ARIA and usually does not.
 */
export function DataTable({ caption, children }: { caption: string; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-left">
        {/* Named for people who cannot see the panel heading above it. */}
        <caption className="sr-only">{caption}</caption>
        {children}
      </table>
    </div>
  );
}

export function THead({ children }: { children: ReactNode }) {
  return (
    <thead className="border-b border-n-200 bg-n-25 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
      {children}
    </thead>
  );
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-n-100">{children}</tbody>;
}

export function Tr({ children }: { children: ReactNode }) {
  return <tr className="h-[var(--row-height)] hover:bg-n-25">{children}</tr>;
}

export function Th({
  children,
  align = "left",
  width,
  ...rest
}: ThHTMLAttributes<HTMLTableCellElement> & {
  align?: "left" | "right";
  /** A Tailwind width class, for the columns that must not breathe. */
  width?: string;
}) {
  return (
    <th
      scope="col"
      className={clsx(
        "px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] font-semibold",
        align === "right" && "text-right",
        width,
      )}
      {...rest}
    >
      {children}
    </th>
  );
}

export function Td({
  children,
  align = "left",
  muted = false,
  ...rest
}: TdHTMLAttributes<HTMLTableCellElement> & {
  align?: "left" | "right";
  /** Secondary facts recede; one strong element per row (docs/09 §5). */
  muted?: boolean;
}) {
  return (
    <td
      className={clsx(
        "px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] align-middle text-body-sm",
        muted ? "text-n-500" : "text-n-900",
        align === "right" && "text-right",
      )}
      {...rest}
    >
      {children}
    </td>
  );
}
