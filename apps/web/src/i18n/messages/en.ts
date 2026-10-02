/**
 * English, the reference dictionary (ADR 0060).
 *
 * Its keys ARE the type every other language must match, so a key added here
 * fails the build until Indonesian has it too. Grouped by screen, because a
 * screen is translated completely or not at all.
 */
export const en = {
  // ── Shared ────────────────────────────────────────────────────────────────
  "common.unreachable": "We could not reach the server. Please try again.",
  "toast.done": "Done",
  "toast.removed": "Removed",
  "toast.dismiss": "Dismiss",

  // ── Shell: sidebar, bottom bar, header ───────────────────────────────────
  "nav.home": "Home",
  "nav.myWork": "My Work",
  "nav.inbox": "Inbox",
  "nav.work": "Work",
  "nav.projects": "Projects",
  "nav.calendar": "Calendar",
  "nav.timesheet": "Timesheet",
  "nav.recurring": "Recurring",
  "nav.flow": "Flow",
  "nav.people": "People",
  "nav.teams": "Teams",
  "nav.departments": "Departments",
  "nav.settings": "Settings",
  "nav.browseAll": "Browse all…",
  "nav.main": "Main",
  "nav.primary": "Primary",
  "nav.search": "Search",
  "nav.more": "More",
  "nav.moreNavigation": "More navigation",
  "nav.notifications": "Notifications",
  "nav.dueOrOverdue": "due or overdue",
  "nav.unread": "unread",
  "nav.countLabel": "{label}: {count} {unit}",

  // ── Account menu ─────────────────────────────────────────────────────────
  "account.menu": "Account",
  "account.profile": "Your profile",
  "account.switchTo": "Switch to",
  "account.signOut": "Sign out",
  "account.oneMoment": "One moment…",

  // ── Command palette ──────────────────────────────────────────────────────
  "palette.search": "Search",
  "palette.placeholder": "Search work, projects, people…",
  "palette.searching": "searching…",
  "palette.nothing": "Nothing matches “{terms}”.",
  "palette.hint": "Search by title, reference, or something said in a comment.",
  "palette.group.work_item": "Work",
  "palette.group.project": "Projects",
  "palette.group.person": "People",
  "palette.match.comment": "in a comment",
  "palette.match.description": "in the description",
  "palette.keys.move": "↑↓ to move",
  "palette.keys.open": "↵ to open",
  "palette.keys.close": "esc to close",
  "palette.tooMany": "Too many searches just now. Try again in a moment.",

  // ── Settings index ───────────────────────────────────────────────────────
  "settings.title": "Settings",
  "settings.areas.one": "{count} area",
  "settings.areas.other": "{count} areas",
  "settings.panel.title": "Areas",
  "settings.panel.description": "Only the ones you may open are listed.",
  "settings.organization.label": "Organization",
  "settings.organization.description": "The place you work, and how long it keeps you signed in.",
  "settings.notifications.label": "Notifications",
  "settings.notifications.description": "Which interruptions reach you, and where.",
  "settings.language.label": "Language",
  "settings.language.description": "Which language this interface speaks to you.",
  "settings.twoFactor.label": "Two-factor authentication",
  "settings.twoFactor.description": "A code from your phone, on top of your password.",
  "settings.sessions.label": "Signed in",
  "settings.sessions.description": "Every device that can act as you, and how to end one.",
  "settings.apiTokens.label": "API tokens",
  "settings.apiTokens.description": "Let a script or an integration act as you, without your password.",
  "settings.serviceAccounts.label": "Service accounts",
  "settings.serviceAccounts.description":
    "Integrations that act in their own name, with a role — and outlive whoever set them up.",
  "settings.sso.label": "Single sign-on",
  "settings.sso.description":
    "The identity provider your people sign in through, and whether passwords still work.",
  "settings.roles.label": "Roles",
  "settings.roles.description": "What each role may do, and the ones you write yourself.",
  "settings.fields.label": "Custom fields",
  "settings.fields.description":
    "What this organization asks about a work item, beyond the built-in fields.",
  "settings.templates.label": "Templates",
  "settings.templates.description":
    "Starting points for new work — a type, a priority, a checklist, a deadline.",
  "settings.webhooks.label": "Webhooks",
  "settings.webhooks.description":
    "Where automation rules may send this organization's events, and whether they arrived.",
  "settings.audit.label": "Audit log",
  "settings.audit.description": "Who did what, and when — sign-ins, invitations, role changes.",
  "settings.workflows.label": "Workflows",
  "settings.workflows.description": "The statuses work moves through, and which moves are legal.",
  "settings.rules.label": "Automation rules",
  "settings.rules.description": "What the system does on its own — and what it has actually done.",

  // ── Settings → Language ──────────────────────────────────────────────────
  "language.title": "Language",
  "language.description": "The language this interface speaks to you, on every device you sign in on.",
  "language.panel.title": "Interface language",
  "language.panel.description":
    "Screens not yet translated stay in English, and so do messages from the server for now.",
  "language.current": "Current",
  "language.saved": "Saved. The interface now speaks {language}.",

  // ── Sign in ──────────────────────────────────────────────────────────────
  "login.metaTitle": "Sign in",
  "login.title": "Sign in",
  "login.subtitle": "Use your organization account.",
  "login.email": "Email",
  "login.password": "Password",
  "login.forgot": "Forgot your password?",
  "login.sso": "Sign in with single sign-on",
  "login.submit": "Sign in",
  "login.submitting": "Signing in…",
  "login.language": "Language",
  "login.code.title": "Enter your code",
  "login.code.body":
    "Six digits from your authenticator app. If you have lost the device, use one of the recovery codes you saved.",
  "login.code.label": "Code",
  "login.code.submit": "Verify",
  "login.code.submitting": "Checking…",
  "login.code.restart": "Start again",
  "login.expired": "This sign-in has expired. Please start again.",
} satisfies Record<string, string>;

export type MessageKey = keyof typeof en;

/** The shape every language must have: exactly English's keys, all strings. */
export type Messages = Record<MessageKey, string>;
