"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { logout } from "@/app/(auth)/login/logout";

/**
 * Identity, and the way out (docs/08 §1).
 *
 * Small on purpose: who you are, a link to your own profile, and sign out. An
 * account menu is where products accumulate everything nobody could place
 * elsewhere, and each addition makes the one thing people open it for harder to
 * find.
 */
export function AccountMenu({
  user,
  membershipId,
  organizationName,
}: {
  user: { id: string; name: string; email: string };
  membershipId: string;
  organizationName: string;
}) {
  const [open, setOpen] = useState(false);
  const [leaving, startTransition] = useTransition();

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((wasOpen) => !wasOpen)}
        className="rounded-sm p-0.5 hover:bg-n-50"
        aria-label="Account"
        aria-expanded={open}
        aria-haspopup="menu"
      >
        <Avatar id={user.id} name={user.name} size="lg" />
      </button>

      {open && (
        <>
          {/* Tapping away closes it, which is what every menu does and
              therefore what the hand expects. */}
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden />

          <div
            role="menu"
            className="absolute right-0 z-20 mt-1 w-56 rounded-md border border-n-200 bg-n-0 py-1 shadow-sm"
          >
            <div className="border-b border-n-100 px-3 pb-2 pt-1">
              <div className="truncate text-body-sm font-medium text-n-900">{user.name}</div>
              <div className="truncate text-caption text-n-500">{user.email}</div>
              <div className="mt-0.5 truncate text-caption text-n-500">{organizationName}</div>
            </div>

            <Link
              href={`/people/${membershipId}`}
              role="menuitem"
              onClick={() => setOpen(false)}
              className="block px-3 py-2 text-body-sm text-n-700 hover:bg-n-50"
            >
              Your profile
            </Link>

            <button
              type="button"
              role="menuitem"
              disabled={leaving}
              onClick={() => startTransition(() => logout())}
              className="block w-full px-3 py-2 text-left text-body-sm text-n-700 hover:bg-n-50 disabled:text-n-300"
            >
              {leaving ? "Signing out…" : "Sign out"}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
