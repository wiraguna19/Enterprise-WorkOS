"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { useT } from "@/i18n/I18nProvider";
import { logout } from "@/app/(auth)/login/logout";
import {
  listOrganizations,
  switchOrganization,
  type OrganizationChoice,
} from "./organization-actions";

/**
 * Identity, and the way out (docs/08 §1).
 *
 * Small on purpose: who you are, a link to your own profile, and sign out. An
 * account menu is where products accumulate everything nobody could place
 * elsewhere, and each addition makes the one thing people open it for harder to
 * find.
 *
 * Switching organization is the one addition, and it earns the place docs/08
 * §1 gives it: "switching tenants is rare and belongs near identity" (ADR
 * 0050). The list is fetched when the menu OPENS, not on every page — most
 * people belong to one organization, and a request on every render to learn
 * that would be paid by everybody for the few. With one organization the
 * section does not appear at all.
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
  const t = useT();
  const [open, setOpen] = useState(false);
  const [leaving, startTransition] = useTransition();
  // Its own transition: loading the list must not grey out "Sign out".
  const [, startLoading] = useTransition();
  const [organizations, setOrganizations] = useState<OrganizationChoice[] | null>(null);
  const [switchError, setSwitchError] = useState<string | null>(null);

  function toggle(): void {
    const opening = !open;

    setOpen(opening);

    // In the handler rather than an effect: the list is wanted because the
    // person opened the menu, and that is an event, not a render.
    if (opening && organizations === null) {
      startLoading(async () => {
        const result = await listOrganizations();

        // A failed list hides the section rather than showing an error in an
        // account menu: switching is rare, and sign-out must stay reachable.
        setOrganizations(result.organizations ?? []);
      });
    }
  }

  const others = (organizations ?? []).filter((organization) => !organization.current);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={toggle}
        className="rounded-sm p-0.5 hover:bg-n-50"
        aria-label={t("account.menu")}
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
              {t("account.profile")}
            </Link>

            {others.length > 0 && (
              <div className="border-t border-n-100 py-1">
                <div className="px-3 pb-1 pt-1 text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
                  {t("account.switchTo")}
                </div>

                {others.map((organization) => (
                  <button
                    key={organization.id}
                    type="button"
                    role="menuitem"
                    disabled={leaving}
                    onClick={() =>
                      startTransition(async () => {
                        const result = await switchOrganization(organization.id);

                        // Only reached on failure: success redirects.
                        setSwitchError(result.error);
                      })
                    }
                    className="block w-full truncate px-3 py-2 text-left text-body-sm text-n-700 hover:bg-n-50 disabled:text-n-300"
                  >
                    {organization.name}
                  </button>
                ))}

                {switchError && (
                  <p role="alert" className="px-3 pb-1 text-caption text-s-danger">
                    {switchError}
                  </p>
                )}
              </div>
            )}

            <button
              type="button"
              role="menuitem"
              disabled={leaving}
              onClick={() => startTransition(() => logout())}
              className="block w-full border-t border-n-100 px-3 py-2 text-left text-body-sm text-n-700 hover:bg-n-50 disabled:text-n-300"
            >
              {leaving ? t("account.oneMoment") : t("account.signOut")}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
