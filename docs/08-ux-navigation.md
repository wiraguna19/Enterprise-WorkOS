# 08 — UX & Navigation Architecture

> The test this design must pass: an employee opens the app and knows what to do
> next within three seconds, without reading anything twice.

---

## 1. Information architecture

The sidebar is small on purpose. Every item added to it makes every other item
harder to find.

```text
┌────────────────────────────────────────────────────────────────────────┐
│  ⌘K Search…            Acme Corp ▾              🔔 3    Sarah Chen ▾   │
├──────────────────┬─────────────────────────────────────────────────────┤
│                  │                                                     │
│  Home            │                                                     │
│  My Work      12 │                  Main content                       │
│  Inbox         3 │                                                     │
│                  │                                                     │
│  PROJECTS     +  │                                                     │
│   ▸ Platform     │                                                     │
│   ▸ Website      │                                                     │
│   ▸ Q3 Campaign  │                                                     │
│     Browse all…  │                                                     │
│                  │                                                     │
│  TEAMS           │                                                     │
│   ▸ Frontend     │                                                     │
│   ▸ Backend      │                                                     │
│                  │                                                     │
│  Calendar        │                                                     │
│  Reports         │                                                     │
│                  │                                                     │
│  ─────────────   │                                                     │
│  People          │  ← admin/manager only                               │
│  Settings        │                                                     │
└──────────────────┴─────────────────────────────────────────────────────┘
```

Decisions embedded here:

- **Only two counters in the sidebar** (My Work, Inbox). If everything has a
  badge, nothing does.
- **Projects and Teams are pinned lists, not full trees.** Users pin the 3–7 they
  actually work in; "Browse all" opens a searchable directory. A sidebar that
  lists 200 projects is a sidebar nobody reads.
- **Role-conditional items** (People, Settings, Reports) appear based on
  permissions, so an employee sees six items, not eleven.
- **The org switcher is in the header, not the sidebar** — switching tenants is
  rare and belongs near identity.
- No "Dashboard" label. It is called **Home**, because that is what it is.

---

## 2. Route map

```text
/login                              /forgot-password  /reset-password
/                                   Home (role-adaptive)
/my-work                            default view: Today
  ?view=today|upcoming|overdue|assigned|created|awaiting-review|completed
/inbox                              notifications + mentions + approval requests
/projects                           directory: search, filter, group by dept
/projects/{key}                     → redirects to the user's last-used view
  /projects/{key}/overview          health, milestones, risk, recent activity
  /projects/{key}/board             kanban by workflow state
  /projects/{key}/list              grouped, sortable, inline-editable table
  /projects/{key}/timeline          milestones + dependencies
  /projects/{key}/calendar          due dates
  /projects/{key}/files             /activity  /settings
/work/{reference}                   ENG-142 — canonical work item URL
/teams/{key}                        overview / workload / work / members
/people                             directory
/people/{id}                        profile, current work, workload, history
/calendar                           cross-source personal calendar
/reports                            /reports/{key}
/settings/…                         profile · notifications · security
                                    organization · departments · teams · people
                                    roles · workflows · custom-fields · tags
                                    integrations · audit-log
```

**`/work/{reference}` is the canonical work item URL** — `ENG-142`, not a UUID.
It is what people paste into chat, and a readable URL is a small thing that
makes a product feel considered.

Two naming deviations from the brief's page list, both deliberate: `/tasks` is
`/work` (a task is one type of work item — `02` §2), and `/employees` is
`/people` (the directory includes contractors and guests, who are not employees).
`/tasks/{id}` and `/employees/{id}` redirect, so no link ever breaks.

Deep links open the item **as a full page**, not as a modal over a random
background. Within a board, clicking a card opens a side panel and updates the
URL; a direct visit renders the full page. Both routes, one component.

---

## 3. Home — role-adaptive, not card soup

The brief's warning is correct: a grid of number cards is not a dashboard. Each
of these screens is built around a single question the person actually has.

### Employee Home — "What should I do now?"

```text
Good afternoon, Sarah                                    Thursday, 20 August

┌─ NEEDS YOUR ATTENTION ─────────────────────────────────────────────────┐
│  ⚠  ENG-140  Fix auth redirect loop        overdue 2 days   [Open]     │
│  ↩  ENG-131  Changes requested by Ahmad     yesterday       [Open]     │
│  ✋ MKT-22   Assigned to you — not accepted  2 hours ago  [Accept][…]  │
└────────────────────────────────────────────────────────────────────────┘

Due today (3)
  ENG-142  Implement assignment history      In Progress   17:00   Platform
  ENG-145  Review API docs                   In Review     17:00   Platform
  OPS-9    Weekly deployment checklist       Todo          18:00   —

This week                                                        [see all →]
  Fri  ENG-147  Migrate workflow states                    Platform
  Mon  MKT-30   Landing page copy review                   Q3 Campaign

Waiting on others (2)
  ENG-138  submitted 3 days ago · awaiting Ahmad
  ENG-120  blocked by ENG-119 (Backend team)

Your week ████████████░░░░  32 of 40 h committed · 2 items unestimated
```

The ordering *is* the design: exceptions first, then commitments, then
foresight, then blockers. Nothing is a card with a number. "Waiting on others"
is included because half of a person's frustration is work they cannot progress,
and no todo list ever shows it.

### Manager Home — "Where is the risk?"

```text
Platform Team · This week

AT RISK                                                        4 items
  ENG-119  Backend migration      David    overdue 3 d   blocks 2 items
  ENG-133  Payment integration    Sarah    due tomorrow  60% · no update 4 d
  MKT-14   Campaign brief         —        unassigned    due in 2 d
  ENG-150  Security review        Ahmad    over capacity this week

PENDING YOUR APPROVAL                                          3 items
  ENG-131  Sarah Chen        submitted 2 h ago        [Review]
  ENG-128  David Park        submitted yesterday      [Review]
  OPS-4    Maya Putri        submitted 3 days ago  ⚠  [Review]

TEAM CAPACITY — week of 17 Aug
  Sarah Chen    ████████████████░░░░  36/40 h    9 items
  David Park    ████████████████████  44/40 h ⚠ 13 items   over-committed
  Ahmad Rizal   ██████████░░░░░░░░░░  22/40 h    5 items
  Maya Putri    ███████████████░░░░░  30/40 h    7 items · 3 unestimated
                                                        [rebalance →]

PROJECTS
  Platform      ███████████████░░░░░  74%   on track      12 open · 1 overdue
  Website       ████████░░░░░░░░░░░░  41%   at risk        8 open · 3 overdue
  Q3 Campaign   ████░░░░░░░░░░░░░░░░  22%   not started   14 open
```

Every row is actionable and links to the thing it describes. There is no
"Total tasks: 847" tile, because no manager has ever made a decision from that
number.

### Organization Home — "How is work flowing?"

Trends over time (throughput, cycle time, overdue rate), distribution across
departments, and a bottleneck list — not a scoreboard of individuals. Per `02`
§11, individual performance is never reduced to a single ranked number.

---

## 4. The two flows that must feel effortless

### Manager: create → assign → monitor → review

```text
⌘K → "new task"  (or C from anywhere, or + on a board column)

┌─ New work item ─────────────────────────────────────────────┐
│ Title                                                       │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Implement assignment history                            │ │
│ └─────────────────────────────────────────────────────────┘ │
│ Description  (optional, markdown, @ to mention)             │
│                                                             │
│ Project [Platform ▾]  Assignee [Sarah ▾]  Due [Sep 4 ▾]     │
│ Priority [High ▾]     Estimate [8h]       Status [Todo ▾]   │
│                                                             │
│ ▸ More: parent, milestone, reviewer, tags, custom fields    │
│                                                             │
│                        [Create]  [Create and add another]   │
└─────────────────────────────────────────────────────────────┘
```

Six fields visible, everything else behind "More". Assignment happens **in the
create step** — a separate assign action afterwards is the single most common
unnecessary click in tools of this kind. Sarah's notification fires on create.

Review, when it comes, is one screen: submission note, attached result, the diff
of what changed since assignment, and two buttons — **Approve** and **Request
changes** (which requires a comment, because a rejection without a reason just
sends the work around the loop again).

### Employee: see → start → work → submit

```text
My Work → ENG-142

┌──────────────────────────────────────────────────────────────────────┐
│ ENG-142 · Platform                              [In Progress ▾] […]  │
│                                                                      │
│ Implement assignment history                                         │
│                                                                      │
│ Assignee Sarah Chen   Reviewer Ahmad Rizal   Due Sep 4, 17:00        │
│ Priority High         Estimate 8h            Logged 5h               │
│──────────────────────────────────────────────────────────────────────│
│ Description                                                          │
│ Assignment must keep full history rather than a pivot table…         │
│                                                                      │
│ Subtasks  2/3                                                        │
│  ✓ Schema + migration          ✓ Repository layer                    │
│  ○ History timeline component                    Sarah · due Sep 3   │
│                                                                      │
│ Attachments (1)  design-notes.pdf                                    │
│──────────────────────────────────────────────────────────────────────│
│ Activity & comments                                                  │
│  Ahmad assigned this to Sarah                            3 days ago  │
│  Sarah started work                                       2 days ago │
│  @Ahmad the history table needs an index on membership_id  1 d ago   │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │ Write a comment…                            @  📎     [Send] │    │
│  └──────────────────────────────────────────────────────────────┘    │
│──────────────────────────────────────────────────────────────────────│
│                                    [Submit for review]  ← primary    │
└──────────────────────────────────────────────────────────────────────┘
```

One primary action, always visible, always the next legal step in the workflow.
It changes with state: Accept → Start → Submit for review → (reviewer sees)
Approve. The user is never asked to work out which status to pick from a
dropdown in order to progress — the dropdown exists for exceptions.

---

## 5. Keyboard model

Enterprise software is used all day by the same people. Keyboard support is not
a power-user nicety; it is the difference between a tool and a chore.

```text
⌘K / Ctrl+K   Command palette — search, navigate, and act
G then H/M/P  Go to Home / My Work / Projects
C             Create work item          /   Focus search
J / K         Move selection            Enter  Open selected
A             Assign                    S      Change status
D             Set due date              M      Comment
E             Edit title inline         X      Select (then bulk actions)
Esc           Close panel / cancel      ?      Shortcut reference
```

The command palette is the primary navigation for experienced users and the
safety net for everyone: it searches work items, projects, and people, and it
executes actions ("assign to David", "due Friday") without leaving the keyboard.

---

## 6. Mobile

Mobile is not the desktop layout at 375 px. Different question, different design:
on a phone, people **check and respond**; they do not plan or administer.

```text
┌─────────────────────┐   Bottom navigation, thumb-reachable:
│  My Work        🔔  │     My Work · Inbox · Search · Me
│─────────────────────│
│  ⚠ Overdue      2   │   Design decisions:
│  ▸ ENG-140          │   • Sections collapse; overdue expanded by default
│  ▸ ENG-131          │   • Swipe right = mark done, left = snooze/reassign
│─────────────────────│   • Detail view is a full screen, not a cramped panel
│  Today          3   │   • Comment box docked above the keyboard
│  ▸ ENG-142      →   │   • Board view is available but not the default —
│  ▸ ENG-145      →   │     horizontal kanban on a phone is a poor interaction
│  ▸ OPS-9        →   │   • Approvals work fully on mobile: read submission,
│─────────────────────│     approve or request changes. Managers approve from
│  This week      5   │     their phone constantly; this must be first-class.
│─────────────────────│   • Admin, reports, and workflow editing are read-only
│ 📋   📥   🔍   👤   │     or unavailable — and say so, rather than rendering
└─────────────────────┘     an unusable table
```

Tablet gets the desktop layout with a collapsible sidebar and larger touch
targets — it is a small laptop, and treating it as a big phone wastes the space.

---

## 7. The Inbox

The Inbox is where the workflow engine surfaces to a person, and it is the
screen most easily ruined. An inbox that reports everything gets muted, and a
muted inbox means a missed approval — at which point the notification layer has
broken the workflow it exists to serve (`12` §10).

Three tabs, in the order a person needs them:

| Tab | Question it answers | Source |
|---|---|---|
| **Needs your decision** | Who is blocked on me? | pending approvals where I am an approver |
| **Waiting on others** | What did I send, and is it moving? | pending approvals I requested |
| **Everything else** | What happened while I was away? | notifications, minus approval ones |

Decisions come first because a pending approval blocks another person's day,
while a notification about a comment does not. The third tab is deliberately
last and deliberately quiet.

**Rules the screen follows:**

- **Decide from the queue.** A reviewer with six submissions and ten minutes
  will clear the queue or abandon it. Making each decision cost a page load, a
  read, and a back-button is what turns "I'll do reviews after standup" into a
  queue nobody clears. The submission note is on the row; the decision belongs
  next to it.
- **Approve is one click; sending work back is not.** The two decisions that
  bounce work open a comment field first, because a rejection without a reason
  sends the work round the loop again. The asymmetry is the design, and the API
  and a CHECK constraint both enforce it independently.
- **Oldest first.** A newest-first review queue starves the submission that has
  been waiting longest — the one most likely to be blocking someone.
- **How long, not when.** "waiting 4h" answers the reviewer's actual question;
  a timestamp makes them do the subtraction.
- **Rows render from the notification's payload snapshot**, never from a live
  join, so a notification still reads correctly after its subject is renamed and
  does not become a blank row if the subject is deleted.
- **Nothing you did yourself appears here.** Telling someone what they just did
  is the fastest way to train them to ignore the badge.

Preferences (`/settings/notifications`) group types by the kind of interruption
rather than by event key, and show two constraints in the interface rather than
enforcing them only on save: immediate email and a digest are mutually
exclusive, and a short list of types cannot be muted in-app at all — being asked
to decide, and being told your work bounced, are not optional.

---

## 8. Cross-cutting interaction rules

- **One primary action per screen**, visually distinct. Everything else is
  secondary or in an overflow menu.
- **Inline editing wherever a value is displayed.** Clicking a due date opens a
  picker in place. Navigating to an edit form to change one field is a
  1998 interaction.
- **Undo over confirm.** Destructive-but-recoverable actions (complete, archive,
  bulk assign) execute immediately with an undo toast. Confirmation dialogs are
  reserved for genuinely irreversible actions, where the dialog names the
  consequence rather than asking "Are you sure?".
- **Optimistic feedback within 100 ms** for every interaction, even if the server
  is still working.
- **Bulk actions** on any list: select with `X` or checkbox, a floating action
  bar appears with assign / status / due date / tag / delete.
- **Saved views** on every list surface — filters a user configures are worth
  keeping, and shareable team views cut a great deal of repeated setup.
- **Density toggle** (comfortable / compact). Enterprise users overwhelmingly
  choose compact; defaulting everyone to airy spacing wastes their screen.
