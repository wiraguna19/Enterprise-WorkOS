import type { ReactNode } from "react";

/**
 * Page titles are the only place `display` type is used, and there is exactly
 * one primary action slot — enforcing "one primary action per screen" as a
 * component contract rather than a guideline (docs/09 §5).
 */
export function PageHeader({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <header className="flex items-start justify-between gap-6 border-b border-n-100 pb-4">
      <div>
        <h1 className="text-display font-semibold text-n-900">{title}</h1>
        {description && <p className="mt-0.5 text-body text-n-500">{description}</p>}
      </div>
      {action}
    </header>
  );
}
