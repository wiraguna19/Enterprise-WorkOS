"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { ToastProvider } from "@/components/ui/Toast";
import { clsx } from "@/lib/clsx";
import { AccountMenu } from "@/features/auth/AccountMenu";
import { CommandPalette } from "@/features/search/CommandPalette";
import { RealtimeProvider } from "@/features/realtime/RealtimeProvider";

/**
 * The application shell (docs/08 §1).
 *
 * The sidebar is small on purpose: every item added makes every other item
 * harder to find. Projects and Teams are pinned lists rather than full trees —
 * a sidebar listing 200 projects is a sidebar nobody reads.
 *
 * Only two counters exist in the whole navigation. If everything has a badge,
 * nothing does.
 */

type NavItem = {
  href: string;
  label: string;
  count?: number;
  /** Which of the two counters this is; decides the badge's colour. */
  counter?: CounterTone;
  permission?: string;
};

/**
 * The two counters do not mean the same thing, so they must not look the same
 * (ADR 0027).
 *
 * My Work counts work that is overdue or due today — a number that is a
 * deadline, and the only one in the chrome that says somebody is late. Inbox
 * counts unread notifications, which is a pile, not a deadline. Rendering both
 * in the same grey (which is what they were) told the reader that four late
 * work items and four unread notices deserve the same reaction.
 */
type CounterTone = "late" | "unread";

const PRIMARY: NavItem[] = [
  { href: "/", label: "Home" },
  { href: "/my-work", label: "My Work", count: 0, counter: "late" },
  { href: "/inbox", label: "Inbox", count: 0, counter: "unread" },
];

const SECONDARY: NavItem[] = [
  { href: "/projects", label: "Projects", permission: "project.view" },
  { href: "/calendar", label: "Calendar" },
  { href: "/time", label: "Timesheet" },
  // Standing instructions to create work. Gated on the permission the route
  // itself requires, so it does not appear for somebody who would only find a
  // refusal behind it.
  { href: "/recurring", label: "Recurring", permission: "work_item.create" },
  { href: "/reports", label: "Flow", permission: "report.view" },
];

const ADMIN: NavItem[] = [
  { href: "/people", label: "People", permission: "person.view" },
  { href: "/teams", label: "Teams", permission: "team.view" },
  // Gated on `department.view` like every other entry here, so it does not
  // appear for somebody who would only find a 403 behind it.
  { href: "/departments", label: "Departments", permission: "department.view" },
  // Pointed straight at the one settings screen that existed, because an index
  // of one adds a click and says nothing. The workflow catalogue and the
  // automation rules made it three, so the index exists now — which was always
  // the condition, not a to-do.
  { href: "/settings", label: "Settings", permission: "organization.view" },
];

export function AppShell({
  user,
  membershipId,
  organization,
  permissions,
  teams,
  counts,
  children,
}: {
  user: { id: string; name: string; email: string };
  membershipId: string;
  organization: { id: string; name: string; slug: string };
  permissions: string[];
  teams: Array<{ id: string; name: string; key: string }>;
  /** Only two counters exist in the whole navigation (docs/08 §1). */
  counts: { myWork: number; inbox: number };
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  // The drawer overlays the page on a phone, so leaving it open after a
  // navigation hides the very screen the user just asked for.
  //
  // Adjusted during render rather than in an effect. This is not a side effect
  // on the outside world — it is state that is simply wrong for the new route,
  // and React re-runs this component immediately with the corrected value
  // instead of painting the stale one and then correcting it. Closing it from
  // each link's onClick would miss every other way a route can change.
  const [renderedPath, setRenderedPath] = useState(pathname);

  if (renderedPath !== pathname) {
    setRenderedPath(pathname);
    setSidebarOpen(false);
  }

  const can = (permission?: string) => !permission || permissions.includes(permission);

  return (
    <ToastProvider>
      <div className="min-h-dvh bg-n-0 text-n-700">
      {/* ── Top bar ─────────────────────────────────────────────────────── */}
      <header className="sticky top-0 z-20 flex h-12 items-center gap-3 border-b border-n-100 bg-n-0 px-3">
        {/* The command palette is the primary navigation for experienced users
            and the safety net for everyone else (docs/08 §5). Both triggers —
            the phone icon and the desktop field — live inside it, because the
            thing that opens the palette and the palette itself share one piece
            of state and splitting them would mean lifting it into the shell. */}
        <CommandPalette />

        <div className="ml-auto flex items-center gap-1">
          {/* Org switcher sits by identity, not in the sidebar: switching
              tenants is rare and belongs near who you are (docs/08 §1). */}
          <span className="hidden text-body-sm text-n-500 sm:inline">{organization.name}</span>

          <Link
            href="/inbox"
            className="relative rounded-sm p-1.5 text-n-500 hover:bg-n-50"
            aria-label="Notifications"
          >
            <span aria-hidden>◔</span>
          </Link>

          <AccountMenu
            user={user}
            membershipId={membershipId}
            organizationName={organization.name}
          />
        </div>
      </header>

      <div className="flex">
        {/* Tapping away closes the drawer, which is what every phone drawer
            does and therefore what the hand expects. Hidden from assistive
            tech: the same escape exists as the toggle button above. */}
        {sidebarOpen && (
          <div
            className="fixed inset-0 top-12 z-[9] bg-black/30 md:hidden"
            onClick={() => setSidebarOpen(false)}
            aria-hidden
          />
        )}

        {/* ── Sidebar ───────────────────────────────────────────────────── */}
        <nav
          className={clsx(
            "fixed inset-y-12 left-0 z-10 w-56 shrink-0 overflow-y-auto border-r border-n-100 bg-n-0 px-2 py-3 md:sticky md:top-12 md:h-[calc(100dvh-3rem)] md:block",
            sidebarOpen ? "block" : "hidden",
          )}
          aria-label="Main"
        >
          <ul className="space-y-0.5">
            {PRIMARY.map((item) => (
              <NavLink
                key={item.href}
                item={{
                  ...item,
                  count:
                    item.href === "/my-work"
                      ? counts.myWork
                      : item.href === "/inbox"
                        ? counts.inbox
                        : undefined,
                }}
                pathname={pathname}
              />
            ))}
          </ul>

          <SidebarSection label="Teams">
            {teams.slice(0, 6).map((team) => (
              <NavLink
                key={team.id}
                item={{ href: `/teams/${team.id}`, label: team.name }}
                pathname={pathname}
              />
            ))}
            {teams.length > 6 && (
              <NavLink item={{ href: "/teams", label: "Browse all…" }} pathname={pathname} />
            )}
          </SidebarSection>

          <SidebarSection>
            {SECONDARY.filter((item) => can(item.permission)).map((item) => (
              <NavLink key={item.href} item={item} pathname={pathname} />
            ))}
          </SidebarSection>

          {ADMIN.some((item) => can(item.permission)) && (
            <div className="mt-3 border-t border-n-100 pt-3">
              <ul className="space-y-0.5">
                {ADMIN.filter((item) => can(item.permission)).map((item) => (
                  <NavLink key={item.href} item={item} pathname={pathname} />
                ))}
              </ul>
            </div>
          )}
        </nav>

        {/* Bottom padding on phones only: the bar below is fixed, so without it
            the last row of every list sits underneath it. */}
        {/* `overflow-x-clip`, so the BOARD is the only thing that scrolls
            sideways. The board's scroller escapes this padding with negative
            margins, which made its content box wider than the column it sits
            in — and a page that scrolls horizontally behind a panel that also
            scrolls horizontally gives you two scrollbars and no idea which one
            moves what. `clip` rather than `hidden`: `hidden` would make this a
            scroll container and break every `sticky` inside it. */}
        <main className="min-w-0 flex-1 overflow-x-clip px-4 pb-24 pt-6 md:px-8 md:pb-6">
          {children}
        </main>
      </div>

      <BottomNav pathname={pathname} counts={counts} onMore={() => setSidebarOpen(true)} />

      {/* Renders nothing. The badges above are server-rendered; this only says
          when to ask for them again, and does nothing at all when real-time is
          unconfigured (docs/07 §8). */}
        <RealtimeProvider organizationId={organization.id} userId={user.id} />
      </div>
    </ToastProvider>
  );
}

/**
 * Phone navigation (docs/08 §6).
 *
 * Thumb-reachable, and deliberately four things: on a phone people check and
 * respond — they do not plan or administer. Everything else is behind "More",
 * which opens the same drawer the desktop sidebar renders, rather than being
 * duplicated into a second phone-only menu that would drift out of step.
 *
 * Search dispatches an event instead of holding the palette's state, because
 * the palette lives in the header and this bar lives at the other end of the
 * tree.
 */
function BottomNav({
  pathname,
  counts,
  onMore,
}: {
  pathname: string;
  counts: { myWork: number; inbox: number };
  onMore: () => void;
}) {
  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-20 flex border-t border-n-100 bg-n-0 pb-[env(safe-area-inset-bottom)] md:hidden"
      aria-label="Primary"
    >
      <BottomLink
        href="/my-work"
        label="My Work"
        icon="▤"
        count={counts.myWork}
        tone="late"
        pathname={pathname}
      />
      <BottomLink
        href="/inbox"
        label="Inbox"
        icon="▣"
        count={counts.inbox}
        tone="unread"
        pathname={pathname}
      />

      <button
        type="button"
        onClick={() => window.dispatchEvent(new Event("palette:open"))}
        className="flex h-14 flex-1 flex-col items-center justify-center gap-0.5 text-n-500"
      >
        <span aria-hidden className="text-body">
          ⌕
        </span>
        <span className="text-micro">Search</span>
      </button>

      <button
        type="button"
        onClick={onMore}
        className="flex h-14 flex-1 flex-col items-center justify-center gap-0.5 text-n-500"
        aria-label="More navigation"
      >
        <span aria-hidden className="text-body">
          ☰
        </span>
        <span className="text-micro">More</span>
      </button>
    </nav>
  );
}

function BottomLink({
  href,
  label,
  icon,
  count,
  tone,
  pathname,
}: {
  href: string;
  label: string;
  icon: string;
  count: number;
  tone: CounterTone;
  pathname: string;
}) {
  const active = pathname === href || pathname.startsWith(`${href}/`);

  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={clsx(
        "relative flex h-14 flex-1 flex-col items-center justify-center gap-0.5",
        active ? "text-a-700" : "text-n-500",
      )}
    >
      <span aria-hidden className="text-body">
        {icon}
      </span>
      <span className="text-micro">{label}</span>

      {count > 0 && (
        <span
          className={clsx(
            // Solid here, where the outlined pill of the sidebar would be a
            // grey smudge at 10px over an icon.
            "absolute right-1/2 top-1.5 ml-3 min-w-4 translate-x-6 rounded-full px-1 text-center text-micro font-semibold tabular-nums text-n-0",
            tone === "late" ? "bg-s-danger" : "bg-a-500",
          )}
        >
          {count > 99 ? "99+" : count}
          <span className="sr-only"> {tone === "late" ? "due or overdue" : "unread"}</span>
        </span>
      )}
    </Link>
  );
}

function SidebarSection({
  label,
  children,
}: {
  label?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="mt-4">
      {label && (
        <h2 className="px-2 pb-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          {label}
        </h2>
      )}
      <ul className="space-y-0.5">{children}</ul>
    </div>
  );
}

function NavLink({ item, pathname }: { item: NavItem; pathname: string }) {
  const active = pathname === item.href || (item.href !== "/" && pathname.startsWith(item.href));

  return (
    <li>
      <Link
        href={item.href}
        aria-current={active ? "page" : undefined}
        className={clsx(
          "flex h-9 items-center justify-between rounded-sm px-2 text-body transition-colors duration-[120ms] ease-standard md:h-7",
          active ? "bg-a-50 font-medium text-a-700" : "text-n-700 hover:bg-n-50",
        )}
      >
        <span className="truncate">{item.label}</span>
        {item.count !== undefined && item.count > 0 && (
          <NavCount count={item.count} tone={item.counter ?? "unread"} label={item.label} />
        )}
      </Link>
    </li>
  );
}

/**
 * A counter in the sidebar, as a pill rather than as grey text.
 *
 * Three decisions worth keeping:
 *
 * - It is bordered, not filled. A filled badge in the chrome is on every screen
 *   at once, so it would win every argument with the one solid badge a screen
 *   is allowed (ADR 0027) — including the late-work badge on My Work itself.
 * - `tabular-nums` so 8 and 88 do not shift the row's right edge as the number
 *   changes under a poll.
 * - The count is announced with its unit. "My Work 4" read aloud says nothing;
 *   the screen reader now hears "My Work, 4 due or overdue".
 */
function NavCount({
  count,
  tone,
  label,
}: {
  count: number;
  tone: CounterTone;
  label: string;
}) {
  const unit = tone === "late" ? "due or overdue" : "unread";

  return (
    <span
      className={clsx(
        "ml-2 shrink-0 rounded-md border px-1.5 py-px text-micro font-semibold tabular-nums",
        tone === "late"
          ? "border-s-danger/30 bg-s-danger/10 text-s-danger"
          : "border-a-500/30 bg-a-50 text-a-700",
      )}
      aria-label={`${label}: ${count} ${unit}`}
    >
      {count > 99 ? "99+" : count}
    </span>
  );
}
