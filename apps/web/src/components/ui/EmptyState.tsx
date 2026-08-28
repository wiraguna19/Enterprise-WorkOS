import type { ReactNode } from "react";

/**
 * An empty state explains what belongs here AND offers the action that creates
 * it. A bare "No data." is the most commonly shipped defect in software like
 * this (docs/07 §7).
 */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-start gap-2 border border-dashed border-n-200 px-6 py-10 rounded-md">
      <h3 className="text-h2 font-semibold text-n-900">{title}</h3>
      <p className="max-w-prose text-body text-n-500">{description}</p>
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
