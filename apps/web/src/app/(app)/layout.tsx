import { redirect } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { api } from "@/lib/api";
import { isSignedOut, requireUser } from "@/lib/auth";

/**
 * Every authenticated route renders inside the shell. The layout is a Server
 * Component: identity, tenant, and navigation data arrive with the HTML, so
 * there is no spinner where the server could have rendered content
 * (docs/07 §2).
 */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  // Permissive: this layout renders around the enrolment screen too, and a
  // redirect here would be a redirect to the page already being rendered
  // (ADR 0033). The PAGES decide; every one of them calls `requireUser()`
  // without this, and the enrolment page is the only one that passes it.
  const me = await requireUser({ allowUnenrolled: true });

  // An organization that requires a second factor confines everybody who has
  // not enrolled to the one screen where they can fix that (ADR 0033).
  //
  // The redirect itself lives in `api()`, not here, and that is the second
  // attempt. The first put it in this layout, which cannot ask which page is
  // rendering inside it — so it needed the path forwarded as a header, and when
  // that header did not arrive the layout redirected the enrolment screen to
  // itself: a blank page and a log filling with 200s. Two mechanisms, one of
  // them half-working, are worse than the one that cannot loop: every refused
  // call already carries the person to enrolment, and the enrolment screen
  // makes no refused call.
  //
  // What stays here is the consequence for the shell.
  const confined = me.organization.requires_second_factor && !me.user.mfa_enabled;

  // A confined session is refused all three of these, correctly, so they are
  // not asked for. The shell renders with an empty sidebar, which is the honest
  // picture of what that session may do.
  const [{ data: teams }, counts, unread] = confined
    ? [{ data: [] }, { overdue: 0, due_today: 0, open: 0, waiting_on_others: 0 }, 0]
    : await Promise.all([
    api<Array<{ id: string; name: string; key: string }>>("/teams", {
      tags: [`org:${me.organization.id}:reference`],
    }),
    // The badge count is a live number, so it is never cached across requests.
    // One grouped query on the API side rather than four (docs/05 §2).
    api<Record<string, number>>("/me/work/counts")
      .then((r) => r.data)
      .catch(() => ({ overdue: 0, due_today: 0, open: 0, waiting_on_others: 0 })),
    // The inbox badge is its own endpoint rather than a length taken from the
    // notification list: the badge must not depend on how many rows the list
    // happened to page in.
    api<{ unread: number }>("/notifications/unread-count")
      .then((r) => r.data.unread)
      .catch(() => 0),
  ]).catch((error: unknown) => {
    if (isSignedOut(error)) redirect("/login");

    throw error;
  });

  return (
    <AppShell
      user={me.user}
      membershipId={me.membership.id}
      organization={me.organization}
      permissions={me.permissions}
      teams={teams}
      counts={{ myWork: counts.overdue + counts.due_today, inbox: unread }}
      confined={confined}
    >
      {children}
    </AppShell>
  );
}
