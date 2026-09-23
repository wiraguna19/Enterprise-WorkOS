import Link from "next/link";
import { clsx } from "@/lib/clsx";

/**
 * The views one project has (docs/08 §2).
 *
 * Shared by every project view rather than written per page: this nav gained a
 * second real entry the day Overview shipped, and a nav that lives in each page
 * is a nav that gains it in one of them.
 *
 * Only views that exist are links. A nav entry that 404s reads as a broken
 * product — the same defect the Phase 5 pass found in the app shell.
 */
const VIEWS = [
  { segment: "overview", label: "Overview" },
  { segment: "board", label: "Board" },
] as const;

/**
 * Shown only to somebody who may change the project (ADR 0040).
 *
 * Gated here AND refused by the page, like every other permission-gated entry
 * in this product: a URL is typed, pasted, bookmarked and followed from an old
 * message, and a nav that merely hides something has not refused it.
 */
const MANAGE = { segment: "settings", label: "Settings" } as const;

/** docs/08 lists these; they ship in later phases. */
const LATER = ["List", "Timeline", "Calendar"];

export function ProjectTabs({
  projectKey,
  active,
  canManage = false,
}: {
  projectKey: string;
  active: (typeof VIEWS)[number]["segment"] | (typeof MANAGE)["segment"];
  /** True when the reader holds `project.update` on this project. */
  canManage?: boolean;
}) {
  const views = [...VIEWS];

  return (
    <nav aria-label="Project views" className="flex gap-4 border-b border-n-100 text-body">
      {views.map((view) => (
        <Link
          key={view.segment}
          href={`/projects/${projectKey}/${view.segment}`}
          aria-current={view.segment === active ? "page" : undefined}
          className={clsx(
            "border-b-2 pb-2 transition-colors duration-[120ms]",
            view.segment === active
              ? "border-a-500 font-medium text-n-900"
              : "border-transparent text-n-500 hover:text-n-700",
          )}
        >
          {view.label}
        </Link>
      ))}

      {/* Views that do not exist yet are real disabled BUTTONS, not greyed
          spans. Two reasons, and the first is not cosmetic:

          a greyed span fails WCAG contrast — axe caught exactly that here,
          because it was using the borders-only token as text (docs/09 §2).
          A genuinely disabled control is exempt, and it is also what these
          are: unavailable actions, announced as such by a screen reader.

          They are shown rather than hidden so the board does not look like
          the only view this product will ever have (docs/07 §4). */}
      {/* Settings comes AFTER the unbuilt views, not between them and the real
          ones: it is not a view of the project, and wedging it in the middle
          separated "the views that exist" from "the views that will" with
          something that is neither. */}
      {LATER.map((view) => (
        <button
          key={view}
          type="button"
          disabled
          title={`${view} view ships in a later phase`}
          className="cursor-not-allowed pb-2 text-n-500/60"
        >
          {view}
        </button>
      ))}

      {canManage && (
        <Link
          href={`/projects/${projectKey}/${MANAGE.segment}`}
          aria-current={active === MANAGE.segment ? "page" : undefined}
          className={clsx(
            "border-b-2 pb-2 transition-colors duration-[120ms]",
            active === MANAGE.segment
              ? "border-a-500 font-medium text-n-900"
              : "border-transparent text-n-500 hover:text-n-700",
          )}
        >
          {MANAGE.label}
        </Link>
      )}
    </nav>
  );
}
