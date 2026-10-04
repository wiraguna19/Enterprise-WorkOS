import Link from "next/link";
import { clsx } from "@/lib/clsx";

export type GlanceItem = {
  href: string;
  count: number;
  label: string;
  /** Said in words beside the number; the colour only repeats it. */
  urgent?: boolean;
};

/**
 * Today in four numbers, each a way through to the list behind it.
 *
 * Not the "Total tasks: 847" grid Home was written against: every number here
 * asks for an action today, is counted the same way as the list it opens, and
 * reads as a phrase ("3 overdue") rather than a metric. A zero is shown as a
 * zero, quietly — "nothing overdue" is worth knowing at a glance too.
 */
export function Glance({ items, label }: { items: GlanceItem[]; label: string }) {
  return (
    <nav aria-label={label}>
      <ul className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        {items.map((item) => (
          <li key={item.href}>
            <Link
              href={item.href}
              className={clsx(
                "flex h-full flex-col gap-0.5 rounded-xl border bg-n-0 px-4 py-3 transition-colors hover:border-n-500",
                item.urgent && item.count > 0 ? "border-s-danger/50" : "border-n-300",
              )}
            >
              <span
                className={clsx(
                  "text-h1 font-semibold tabular-nums",
                  item.count === 0 ? "text-n-500" : item.urgent ? "text-s-danger" : "text-n-900",
                )}
              >
                {item.count}
              </span>
              <span className="text-body-sm text-n-700">{item.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
