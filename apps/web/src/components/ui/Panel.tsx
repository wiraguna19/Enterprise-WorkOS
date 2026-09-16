import { clsx } from "@/lib/clsx";
import type { ReactNode } from "react";

/**
 * A bordered container with a name on it (docs/09 §5, ADR 0024).
 *
 * Until this existed every screen improvised its own section — a bare `<h2>`
 * over a `divide-y` list — so nothing on a page had an edge, and a form, a list
 * and a paragraph all floated in the same undifferentiated column. That reads
 * as unfinished rather than as restrained.
 *
 * Elevation 0: a border, never a shadow. A panel is part of the page, not a
 * card lying on top of it, and docs/09 §4 reserves shadows for surfaces that
 * genuinely float. Radius 6, which is the panel step of the same scale.
 *
 * The heading is a real `<h2>` with an id, and the section is labelled by it.
 * That is not decoration either: the settings index already depends on
 * accessible names for its links, and the E2E suite finds half the product by
 * role and name.
 */
export function Panel({
  id,
  title,
  description,
  actions,
  footer,
  bleed = false,
  tone = "default",
  children,
}: {
  /** Used for the heading's id, so the section can be labelled by it. */
  id: string;
  title: string;
  description?: ReactNode;
  /** Right-aligned controls that belong to the section, not to a row. */
  actions?: ReactNode;
  /** A form or a summary that belongs under the content, on its own surface. */
  footer?: ReactNode;
  /** True when the body is a table or a list that should meet the border. */
  bleed?: boolean;
  /** `danger` outlines a section whose actions cannot be undone. */
  tone?: "default" | "danger";
  children: ReactNode;
}) {
  return (
    <section
      aria-labelledby={`${id}-heading`}
      className={clsx(
        "overflow-hidden rounded-xl border bg-n-0",
        tone === "danger" ? "border-s-danger/50" : "border-n-300",
      )}
    >
      <header
        className={clsx(
          "flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b px-4 py-2.5",
          tone === "danger" ? "border-s-danger/40 bg-s-danger/5" : "border-n-200 bg-n-25",
        )}
      >
        <div className="min-w-0">
          <h2
            id={`${id}-heading`}
            className={clsx(
              "text-body font-semibold",
              tone === "danger" ? "text-s-danger" : "text-n-900",
            )}
          >
            {title}
          </h2>

          {description && <p className="mt-0.5 text-body-sm text-n-500">{description}</p>}
        </div>

        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
      </header>

      <div className={bleed ? "" : "px-4 py-3"}>{children}</div>

      {footer && <div className="border-t border-n-200 bg-n-25 px-4 py-3">{footer}</div>}
    </section>
  );
}
