import Link from "next/link";
import type { Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
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
  { segment: "overview", label: "tabs.overview" },
  { segment: "board", label: "tabs.board" },
] as const;

/**
 * Shown only to somebody who may change the project (ADR 0040).
 *
 * Gated here AND refused by the page, like every other permission-gated entry
 * in this product: a URL is typed, pasted, bookmarked and followed from an old
 * message, and a nav that merely hides something has not refused it.
 */
const MANAGE = { segment: "settings", label: "nav.settings" } as const;

/** docs/08 lists these; they ship in later phases. */
// Dictionary keys (ADR 0060); the labels are looked up at render.
const LATER: MessageKey[] = ["tabs.list", "tabs.timeline", "tabs.calendar"];

export function ProjectTabs({
  projectKey,
  active,
  canManage = false,
  locale = "en",
}: {
  projectKey: string;
  active: (typeof VIEWS)[number]["segment"] | (typeof MANAGE)["segment"];
  /** True when the reader holds `project.update` on this project. */
  canManage?: boolean;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);
  const views = [...VIEWS];

  // Scrolls sideways rather than wrapping or spilling. Six entries do not fit
  // 375px, and without `overflow-x-auto` the last of them — Settings, the only
  // way into a project's members — sat past the right edge of a phone with no
  // way to reach it. The mobile run of the project flow found it by timing out.
  return (
    <nav
      aria-label={t("tabs.label")}
      className="flex gap-4 overflow-x-auto whitespace-nowrap border-b border-n-100 text-body"
    >
      {views.map((view) => (
        <Link
          key={view.segment}
          href={`/projects/${projectKey}/${view.segment}`}
          aria-current={view.segment === active ? "page" : undefined}
          className={clsx(
            "shrink-0 border-b-2 pb-2 transition-colors duration-[120ms]",
            view.segment === active
              ? "border-a-500 font-medium text-n-900"
              : "border-transparent text-n-500 hover:text-n-700",
          )}
        >
          {t(view.label)}
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
          title={t("tabs.later", { view: t(view) })}
          className="shrink-0 cursor-not-allowed pb-2 text-n-500/60"
        >
          {t(view)}
        </button>
      ))}

      {canManage && (
        <Link
          href={`/projects/${projectKey}/${MANAGE.segment}`}
          aria-current={active === MANAGE.segment ? "page" : undefined}
          className={clsx(
            "shrink-0 border-b-2 pb-2 transition-colors duration-[120ms]",
            active === MANAGE.segment
              ? "border-a-500 font-medium text-n-900"
              : "border-transparent text-n-500 hover:text-n-700",
          )}
        >
          {t(MANAGE.label)}
        </Link>
      )}
    </nav>
  );
}
