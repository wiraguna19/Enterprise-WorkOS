import { AppShell } from "@/components/AppShell";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Every authenticated route renders inside the shell. The layout is a Server
 * Component: identity, tenant, and navigation data arrive with the HTML, so
 * there is no spinner where the server could have rendered content
 * (docs/07 §2).
 */
export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const me = await requireUser();

  // Reference data the navigation needs, cached per organization and
  // invalidated on change rather than on a timer (docs/07 §2).
  const [{ data: teams }, counts, unread] = await Promise.all([
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
  ]);

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
