import Link from "next/link";

/**
 * Where this page sits, when it genuinely sits somewhere (ADR 0026).
 *
 * Only for a hierarchy that is REAL — a work item inside its project, a team
 * inside the teams list, a department inside its parent. A trail is a promise
 * that the levels above exist and contain this one; inventing "Settings ›
 * Roles" for a screen you reached from the nav is decoration that teaches
 * people to stop reading the trail.
 *
 * The last entry is the page itself: not a link, and marked `aria-current` so a
 * screen reader says "current page" rather than offering a link to where the
 * reader already is.
 *
 * The separators are `aria-hidden`. A list of links read aloud as "Projects
 * slash Platform Rebuild slash" is worse than the silence.
 */
export type Crumb = { label: string; href?: string };

export function Breadcrumb({ items }: { items: Crumb[] }) {
  if (items.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-x-1.5 text-body-sm text-n-500">
        {items.map((item, index) => {
          const last = index === items.length - 1;

          return (
            <li key={`${item.label}-${index}`} className="flex items-center gap-x-1.5">
              {index > 0 && (
                <span aria-hidden className="text-n-300">
                  ›
                </span>
              )}

              {item.href && !last ? (
                <Link href={item.href} className="hover:text-a-700 hover:underline">
                  {item.label}
                </Link>
              ) : (
                <span className={last ? "text-n-700" : undefined} aria-current={last ? "page" : undefined}>
                  {item.label}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
