import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { PreferenceGroup } from "@/features/settings/NotificationPreferences";
import type { NotificationType, Preference } from "@/features/settings/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

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

// Keys rather than words: the labels are resolved per reader in the page, so
// the same three groups read in whichever language the reader chose.
const GROUPS: Array<{
  id: string;
  label: MessageKey;
  description: MessageKey;
  types: NotificationType[];
}> = [
  {
    id: "decisions",
    label: "nprefs.group.decisions",
    description: "nprefs.group.decisions.description",
    types: [
      { key: "approval.requested", label: "Someone asks for your review", alwaysInApp: true },
      { key: "approval.changes_requested", label: "Your work is sent back", alwaysInApp: true },
      { key: "approval.approved", label: "Your work is approved" },
    ],
  },
  {
    id: "your-work",
    label: "nprefs.group.yourWork",
    description: "nprefs.group.yourWork.description",
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
    id: "work-you-follow",
    label: "nprefs.group.following",
    description: "nprefs.group.following.description",
    types: [
      { key: "work.completed", label: "Work you watch is completed" },
      { key: "work.commented", label: "Work you watch gets a comment" },
    ],
  },
];

export default async function NotificationPreferencesPage() {
  const me = await requireUser();
  const t = translator(asLocale(me.user.locale));

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
      type: { ...type, label: t(`nprefs.type.${type.key}` as MessageKey) },
      saved: preferences.find((p) => p.type === type.key) ?? { type: type.key, ...defaults },
    }));

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("nav.notifications")}
        description={t("nprefs.description")}
      />

      <PageBody>
        {GROUPS.map((group) => (
          <Panel
            key={group.id}
            id={`group-${group.id}`}
            title={t(group.label)}
            description={t(group.description)}
            bleed
          >
            <PreferenceGroup entries={entries(group.types)} />
          </Panel>
        ))}
      </PageBody>
    </div>
  );
}
