import Link from "next/link";
import { Icon, type IconName } from "@/components/ui/Icon";
import { clsx } from "@/lib/clsx";

export type GlanceItem = {
  href: string;
  count: number;
  /** What the number counts: "Overdue". */
  label: string;
  /** One line saying what it means or what to do: "Past their due date". */
  caption: string;
  icon: IconName;
  /** Said in words beside the number; the colour only repeats it. */
  urgent?: boolean;
};

/**
 * Today in four numbers, each a way through to the list behind it.
 *
 * Not the "Total tasks: 847" grid Home was written against: every number here
 * asks for an action today and is counted the same way as the list it opens.
 * Each card says what it counts at the top, the number in the middle, and what
 * it means at the bottom, beside the arrow that says it opens — so the card can
 * be read by someone who has never seen this product before. A zero is shown
 * as a zero, quietly: "nothing overdue" is worth knowing at a glance too.
 */
export function Glance({ items, label }: { items: GlanceItem[]; label: string }) {
  return (
    <nav aria-label={label}>
      <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {items.map((item) => {
          const alarming = item.urgent === true && item.count > 0;

          return (
            <li key={item.href}>
              <Link
                href={item.href}
                className={clsx(
                  "group flex h-full flex-col gap-3 rounded-xl border bg-n-0 p-4 transition-colors hover:border-n-500",
                  alarming ? "border-s-danger/50" : "border-n-300",
                )}
              >
                <span className="flex items-start justify-between gap-2">
                  <span className="text-body-sm text-n-700">{item.label}</span>
                  <Icon name={item.icon} className={clsx("size-4", alarming ? "text-s-danger" : "text-a-500")} />
                </span>

                <span
                  className={clsx(
                    "text-h1 font-semibold tabular-nums leading-none",
                    item.count === 0 ? "text-n-500" : alarming ? "text-s-danger" : "text-n-900",
                  )}
                >
                  {item.count}
                </span>

                <span className="mt-auto flex items-end justify-between gap-2">
                  <span className="text-caption text-n-500">{item.caption}</span>
                  <Icon name="arrowUpRight" className="size-3.5 text-n-500 transition-colors group-hover:text-n-900" />
                </span>
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
