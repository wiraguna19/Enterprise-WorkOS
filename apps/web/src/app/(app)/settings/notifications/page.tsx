import { PageHeader } from "@/components/ui/PageHeader";
import { PreferenceGroup } from "@/features/settings/NotificationPreferences";
import type { NotificationType, Preference } from "@/features/settings/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Notification preferences (docs/08 §7).
 *
 * The types are listed by what they MEAN to the person, not by their event key,
 * and they are grouped so the choice is about a kind of interruption rather
 * than about an implementation detail.
 *
 * The two constraints the controls express — email and digest are mutually
 * exclusive, and a few types cannot be muted in app — are enforced in the
 * database; PreferenceGroup renders them, and this file only decides which
 * types exist and how they are grouped.
 */

const GROUPS: Array<{
  label: string;
  description: string;
  types: NotificationType[];
}> = [
  {
    label: "Decisions",
    description: "Things that block someone until you act.",
    types: [
      { key: "approval.requested", label: "Someone asks for your review", alwaysInApp: true },
      { key: "approval.changes_requested", label: "Your work is sent back", alwaysInApp: true },
      { key: "approval.approved", label: "Your work is approved" },
    ],
  },
  {
    label: "Your work",
    description: "Changes to what you are responsible for.",
    types: [
      { key: "work.assigned", label: "Work is assigned to you", alwaysInApp: true },
      { key: "work.mentioned", label: "You are mentioned in a comment" },
      { key: "work.due_soon", label: "Your work is due soon" },
    ],
  },
  {
    label: "Work you follow",
    description: "Items you watch but do not own. The usual first thing to turn down.",
    types: [
      { key: "work.completed", label: "Work you watch is completed" },
      { key: "work.commented", label: "Work you watch gets a comment" },
    ],
  },
];

export default async function NotificationPreferencesPage() {
  await requireUser();

  const saved = await api<Preference[]>("/notifications/preferences")
    .then((r) => r.data)
    .catch(() => [] as Preference[]);

  // An absent row means the default for that type, so a new notification type
  // never requires backfilling a row for every member of every organization.
  const preferenceFor = (key: string): Preference =>
    saved.find((p) => p.type === key) ?? { type: key, in_app: true, email: false, digest: "off" };

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title="Notifications"
        description="What reaches you, and how. Anything not listed here does not notify anyone."
      />

      {GROUPS.map((group) => (
        <section key={group.label} aria-labelledby={`group-${group.label}`}>
          <h2 id={`group-${group.label}`} className="text-h2 font-semibold text-n-900">
            {group.label}
          </h2>
          <p className="mt-0.5 text-body-sm text-n-500">{group.description}</p>

          <PreferenceGroup types={group.types} preferenceFor={preferenceFor} />
        </section>
      ))}
    </div>
  );
}
