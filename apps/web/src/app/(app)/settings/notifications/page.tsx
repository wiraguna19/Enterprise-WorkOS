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
      // `comment.mentioned`, which is what the product sends. This said
      // `work.mentioned` — a key nothing dispatches — so the toggle wrote a
      // preference row no dispatcher would ever read. A control for a type
      // that does not exist cannot fail visibly, which is how it survived
      // beside a mention feature that notified nobody at all.
      { key: "comment.mentioned", label: "You are mentioned in a comment" },
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

  // The endpoint answers with BOTH the saved rows and the defaults, in one
  // object — not a bare array. This page read it as an array and called
  // `.find()` on it, which throws: the screen has been an error boundary, not a
  // settings page. A hand-written contract drifts, and TypeScript believes
  // whatever the `api<T>` call claims (docs/07 §3).
  const { preferences, defaults } = await api<{
    preferences: Preference[];
    defaults: Omit<Preference, "type">;
  }>("/notifications/preferences")
    .then((r) => r.data)
    .catch(() => ({
      preferences: [] as Preference[],
      // Only reached when the endpoint itself failed. It is the API's list that
      // decides; this is the shape to render while it is unreachable, and the
      // screen says nothing was loaded rather than pretending these are yours.
      defaults: { in_app: true, email: false, digest: "off" } as Omit<Preference, "type">,
    }));

  // An absent row means the default for that type, so a new notification type
  // never requires backfilling a row for every member of every organization.
  // The defaults come from the API rather than a copy kept here — the second
  // copy was already in this file, one edit away from disagreeing with the
  // server about what "unset" means.
  // Resolved HERE, into data, because a prop crossing into a client component
  // is serialized — and a function is not serializable. Passing
  // `preferenceFor` threw at render and made this whole screen an error
  // boundary, which is the second time this page has been one. Neither was
  // visible to a type-checker or to the reachability guard: both are runtime
  // truths that only opening the screen can find.
  const entries = (types: NotificationType[]) =>
    types.map((type) => ({
      type,
      saved: preferences.find((p) => p.type === type.key) ?? { type: type.key, ...defaults },
    }));

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

          <PreferenceGroup entries={entries(group.types)} />
        </section>
      ))}
    </div>
  );
}
