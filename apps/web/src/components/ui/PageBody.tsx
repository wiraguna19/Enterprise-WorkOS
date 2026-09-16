import type { ReactNode } from "react";

/**
 * The page's horizontal rhythm, in one place (ADR 0024).
 *
 * Screens were setting their own width: the person profile centred itself in a
 * `max-w-4xl` while the role and denial sections beneath it ran the full width
 * of the window, so one page had two different left edges and a ragged right
 * one. Nobody notices the rule; everybody notices its absence.
 *
 * The aside is not a sidebar of leftovers. It holds the facts somebody GLANCES
 * at — reporting line, capacity, this week — while the main column holds what
 * they came to read or change. On a phone it stacks under the main column,
 * because a glance is worth less than the thing itself.
 */
export function PageBody({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  if (!aside) {
    return <div className="mx-auto w-full max-w-5xl space-y-4">{children}</div>;
  }

  return (
    <div className="mx-auto grid w-full max-w-6xl grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="min-w-0 space-y-4">{children}</div>
      <aside className="space-y-4">{aside}</aside>
    </div>
  );
}
