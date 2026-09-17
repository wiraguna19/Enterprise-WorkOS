import { headers } from "next/headers";
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
  const me = await requireUser();

  // An organization that requires a second factor confines everybody who has
  // not enrolled to the one screen where they can (ADR 0033). The API refuses
  // them everything else with a 403 regardless; this exists so a person meets a
  // form rather than an error on every link they press.
  //
  // Here rather than in `proxy.ts`, which reads a cookie and never a session
  // and so cannot know who is signed in — and the path comes from the header
  // that file now forwards, because a layout cannot ask which page is
  // rendering inside it and redirecting the enrolment screen to itself is a
  // loop.
  const confined = me.organization.requires_second_factor && !me.user.mfa_enabled;
  const pathname = (await headers()).get("x-pathname") ?? "";

  if (confined && !pathname.startsWith("/settings/two-factor")) {
    redirect("/settings/two-factor");
  }

  // Reference data the navigation needs, cached per organization and
  // invalidated on change rather than on a timer (docs/07 §2).
  // Belt to `requireUser()`'s braces. A session can be revoked between the two
  // calls — that is a second, not a hypothetical — and an unhandled 401 here
  // renders a 500 page to somebody whose only problem is that they need to sign
  // in again. The person on the other device pressed "End"; what they should
  // get is the sign-in screen (ADR 0023).
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
    >
      {children}
    </AppShell>
  );
}
