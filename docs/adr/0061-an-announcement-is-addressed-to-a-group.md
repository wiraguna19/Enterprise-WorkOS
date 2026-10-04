# ADR 0061: An announcement is addressed to a group

- **Status:** accepted
- **Date:** 2026-10-04
- **Phase:** after Phase 7 (the Home queue, part 1 of 3: announcements, then KPIs, then Home)
- **Relates to:** `docs/06` §2 (permissions, scoped grants), ADR 0013 (who may notify), ADR 0060

## Context

The Home screen is meant to carry announcements addressed to groups. Nothing in
the product can say something to a group. A comment belongs to a work item, and
a notification is a message *about* something rather than a message in itself.
Home is the last of three items because it summarises the other two, so this
ADR comes first.

## Decision

**An announcement is a short text with a title. It is addressed to exactly one
audience: the whole organization, one department, or one team. Its audience
cannot change after it is published.**

- **Who receives it.**
  - An organization announcement reaches every active member.
  - A department announcement reaches everyone whose profile is in that
    department *or any department below it*, plus everyone on a team that
    belongs to one of those departments. A sub-department is part of its
    department. If an announcement to Engineering missed Quality Assurance,
    people would learn the tree is a lie.
  - A team announcement reaches the team's active members.
- **Membership is resolved when the announcement is read, not when it is
  published.** Someone who joins a team next week sees the team's pinned
  announcement next week. Notifications are the exception. They go out once, to
  whoever was in the audience at publication, because a notification is an
  event.
- **Who may publish:**
  - `announcement.publish` allows any audience. It is granted to org admins.
  - `announcement.publish_own_group` allows a department the person heads (or
    any below it), or a team they lead, or any team inside a department they
    head. It is granted to managers.
  - A scoped grant of `announcement.publish` on a team or department
    (`docs/06` §2) also allows that one group. This is how an organization lets
    a team lead who is not a manager speak to their own team.
  - The third rule is a relationship, which `docs/06` §2 warns against. It is
    not the `if (lead)` that the warning means. The *permission* decides
    whether a role may speak for the groups it runs, and the organization can
    remove it or grant it to anyone. The relationship only says *which* groups
    those are.
  - The publish route has no permission middleware. Middleware only knows
    organization-wide permissions, so it would refuse exactly the person a
    scoped grant was written for.
  - One class, `AnnouncementAuthority`, answers the question "may this person
    address this group?". The same class also serves the list of audiences to
    the compose form. A second copy of the rule in the web would eventually
    disagree with the first (`layered_authorization`).
- **Who may change or remove it:** the author, or anyone holding
  `announcement.publish`. A manager who lost the permission can still remove
  what they said. Retracting your own words should not need a grant.
- **What it carries.**
  - Title (≤ 160) and body (≤ 5000, plain text with line breaks; no HTML, no
    Markdown in this slice).
  - *Pinned*: it shows first, and on Home.
  - An optional *expiry*: after that date it leaves the feed but is still
    listed for those who manage it.
  - *Requires acknowledgement*: each reader confirms they read it, and the
    publisher sees who has not. This is for a policy or a schedule change,
    where "did everyone see this?" is the actual question. It is opt-in per
    announcement. If every announcement asked for a click, the click would stop
    meaning anything.
- **Read state is per person and separate from acknowledgement.** Opening the
  list marks what is on it as read. Acknowledging is a deliberate act.
- **The publisher sees counts, plus names only for acknowledgement.** "41 of 50
  have read this" helps someone decide whether to repeat it. A list of who has
  not opened a casual announcement is surveillance with no purpose. The list of
  who has not *acknowledged* is the purpose of that flag.
- **Publication notifies** with `announcement.published`, through
  `NotificationDispatcher`, which the module calls directly (ADR 0013). The
  author is not notified. People can mute the type like any other.

## Consequences

- A new module, `Announcement`. It may use Platform, Identity, Organization,
  Notification and Governance. Nothing depends on it, so it cannot close a cycle.
- Erasing a person removes their read and acknowledgement rows. Announcements
  they wrote stay, with the author shown as a former member. What was said to
  the organization is a record of the organization.
- Not in this slice: scheduling for a later date, attachments, comments or
  reactions, and email delivery. Each fits the table as designed.
