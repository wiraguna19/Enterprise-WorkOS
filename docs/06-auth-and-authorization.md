# 06 — Authentication, Authorization & Security

---

## 1. Authentication

### Chosen mechanism

**Opaque, server-side session tokens** (Laravel Sanctum personal access tokens,
hashed at rest), delivered to the browser inside an `HttpOnly` cookie set by the
Next.js server.

Not JWTs. The reason is specific to this domain: **revocation must be
immediate.** When an employee is removed, a role is downgraded, or a session is
found to be compromised, access must end at that moment — not when a 15-minute
JWT expires. In a work platform holding an organization's internal operations,
"the fired employee kept access for another ten minutes" is not acceptable, and
the machinery required to make JWTs revocable (a denylist checked on every
request) reproduces the database lookup that JWTs were meant to avoid. Opaque
tokens with a hot Redis-cached lookup are simpler and strictly safer.

### Login flow

```text
Browser ──POST /api/auth/login──► Next.js route handler
                                       │
                                       ├─► POST /api/v1/auth/login (Laravel)
                                       │      validate credentials (Argon2id)
                                       │      check membership status
                                       │      MFA challenge if enabled
                                       │      create sessions row, return token
                                       │      write audit_logs: auth.login
                                       │
                                       └─► Set-Cookie: __Host-session=<token>
                                              HttpOnly; Secure; SameSite=Lax;
                                              Path=/; Max-Age=…
Browser ◄─── 204 (no token in the JS heap, ever)
```

Subsequent requests: the Next.js server reads the cookie and forwards
`Authorization: Bearer <token>` to the API. The token is never exposed to
client-side JavaScript, so an XSS payload cannot exfiltrate it.

### Session policy

| Setting | Value |
|---|---|
| Idle timeout | 8 hours (org-configurable) |
| Absolute lifetime | 30 days with sliding refresh |
| Rotation | New token on privilege change and on password change |
| Concurrent sessions | Allowed, all listed in Settings → Security, individually revocable |
| Revoke-all triggers | Password change, MFA change, role change, membership revocation |
| Storage | `sessions.token_hash` (SHA-256). The raw token is never persisted. |

### Password and MFA

- Argon2id, sensible memory/time cost, tuned per environment.
- Minimum 12 characters, checked against the k-anonymity HaveIBeenPwned range
  API. Composition rules (one symbol, one digit) are **not** used — they measurably
  produce worse passwords.
- Reset tokens: single-use, 60-minute expiry, hashed at rest, invalidate all
  sessions on use.
- TOTP MFA available from Phase 2, **enforceable per organization** from Phase 7.
  Recovery codes are single-use and hashed.
- Login responses are constant-time and identical for "unknown email" and "wrong
  password" — no user enumeration.

### Organization context

A user with several memberships selects an active organization after login; the
choice is bound to the session row (`sessions.organization_id`), not to a header
the client can change. Switching organizations issues a new token. **The client
cannot influence tenant scope.** This closes the most obvious multi-tenant
attack: sending someone else's `organization_id`.

---

## 2. Authorization

### Layered model

```text
1. Authenticated?              middleware → 401
2. Member of the tenant?       TenantContext → 404 (never 403 across tenants)
3. Has the permission?         RBAC check   → 403
4. Has access to this record?  Policy       → 403 / 404
5. Is this transition legal?   Domain guard → 409
```

All five are server-side. **The frontend never makes an authorization decision**
— it reads the `permissions` block the server returns and hides what the server
already decided is unavailable. Hiding a button is a UX courtesy; it is not
security, and the API behaves identically whether or not the button existed.

### Permission catalogue

Permissions are `resource.action` strings, seeded globally:

```text
organization.view          organization.update        organization.manage_billing
department.*               team.*                     person.invite / person.deactivate
role.view / role.manage    audit_log.view             settings.manage
project.view / create / update / delete / archive / manage_members
work_item.view / create / update / delete
work_item.assign           work_item.transition       work_item.submit
work_item.transition_any   (override guards)
approval.request           approval.decide            approval.decide_any
comment.create / update_own / delete_any
file.upload / file.delete_any
workflow.manage            custom_field.manage        report.view / report.export
```

### Default roles (seeded, editable per organization)

| Role | Shape |
|---|---|
| **Super Admin** | Platform-level. `users.is_platform_admin`. Cross-tenant access only via an explicit, audited impersonation flow with a reason string. Not an ordinary role. |
| **Organization Admin** | Everything within one organization, including roles, people, settings, audit log. Cannot access other tenants. |
| **Manager** | Project create/update, work item full lifecycle, assign, approve, team and workload visibility, reports. Not org settings, not roles, not audit log. |
| **Employee** | View and act on work they are assigned to or that lives in projects they are a member of. Create work, comment, upload, submit. Cannot assign to others or approve. |
| **Viewer / Guest** | Read-only, restricted to explicitly granted projects. Comments off by default. |

Roles are compositions of permissions, not hardcoded checks. **`if ($user->role
=== 'manager')` never appears in the codebase** — the day a customer wants a
"Team Lead" who can assign but not approve, that line is a rewrite; a permission
check is a configuration change.

### Scoped permissions

Global role + scoped grants, resolved most-specific-first:

```text
effective(user, permission, resource) =
      scoped_role_assignments matching (project|team|department of resource)
   ∪  project_members.role grants for the resource's project
   ∪  org-wide roles via membership_roles
   →  union of permissions; explicit deny wins where present (Phase 7)
```

Resolution result is cached in Redis per (membership, scope) with a version
counter on the membership; any role change bumps the counter and invalidates
instantly. A permission check must never be a database round trip on a hot list
endpoint.

### Visibility rules for work items

Layered, and evaluated in the query, not after fetching:

```text
An actor can see a work item if ANY holds:
  • they have work_item.view org-wide and the project is not private
  • they are an active or historical assignee/reviewer/watcher
  • they are a member of the item's project
  • they are the creator
  • they manage (transitively, via employee_profiles) the current assignee
  • the item has no project and they created it or are assigned
```

This is expressed once, as a query scope (`visibleTo(Membership)`), applied by
the repository to every list and detail read. Implementing visibility per
endpoint is how a leak eventually ships.

---

## 3. Security controls

| Area | Control |
|---|---|
| Transport | HTTPS only, HSTS with preload, secure cookies |
| Headers | CSP (nonce-based, no `unsafe-inline`), `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` |
| CSRF | Double-submit token on all BFF mutations; cookie is `SameSite=Lax`; the API itself is stateless-bearer and therefore not CSRF-exposed |
| SQL injection | Parameter binding everywhere; raw SQL only in reviewed, parameterised report queries |
| XSS | React escaping by default; user markdown rendered through a strict allowlist sanitizer server-side; no `dangerouslySetInnerHTML` on user content without sanitisation |
| Mass assignment | DTOs from validated FormRequests only; `$guarded = ['*']` on every model — never `$fillable` maintained by hand |
| IDOR | UUID v7 keys + tenant scope + per-record policy. Enumeration is neither possible nor sufficient. |
| File upload | Extension **and** magic-byte MIME check, size cap, filename normalised, stored under a generated path, served from a separate origin with `Content-Disposition: attachment`, virus scan before availability, no SVG rendering inline |
| Rate limiting | Per `05` §7, with account lockout and alerting on auth abuse |
| Secrets | Environment only; never in the repo; rotated; `mfa_secret` encrypted at rest with the app key |
| PII in logs | Structured logs redact email, tokens, file contents; audit log stores an email *snapshot* deliberately and that table's access is itself audited |
| Dependencies | `composer audit` + `npm audit` + Dependabot in CI; a critical advisory fails the build |
| Tenant isolation | `01` §6, plus the automated cross-tenant test suite |

### Audited events (non-negotiable list)

```text
auth.login  auth.login_failed  auth.logout  auth.mfa_enabled  auth.mfa_disabled
auth.password_changed  auth.password_reset  auth.session_revoked
person.invited  person.joined  person.deactivated  person.reactivated
role.created  role.updated  role.deleted  role.assigned  role.revoked
permission.granted  permission.revoked
organization.settings_updated  workflow.updated
project.deleted  work_item.deleted  work_item.bulk_updated
file.downloaded (sensitive scopes)  report.exported  data.exported
admin.impersonation_started  admin.impersonation_ended
```

Each row records actor, target, IP, user agent, timestamp, and an immutable
metadata snapshot. The table is append-only at the database level (`03` §6).

---

## 4. Threat model — what is explicitly defended against

| Threat | Defence |
|---|---|
| Cross-tenant data access | Mandatory global scope + composite FKs + reflection-driven test suite + 404 (not 403) across tenants |
| Token theft via XSS | Token never in JS; `HttpOnly` cookie; strict CSP |
| Privilege escalation via client tampering | All authorization server-side; tenant from session, not request |
| Stale access after offboarding | Opaque revocable sessions; revoke-all on membership change |
| Lost updates on concurrent edit | Optimistic locking with 409 + merge UI |
| Duplicate side effects from retries | Idempotency keys; idempotent queue jobs |
| Runaway automation | Workflow recursion guard with causation depth limit |
| Malicious upload | Magic-byte check, scan-before-serve, separate origin, no inline render |
| Audit tampering | Append-only trigger; audit access is itself audited |
| Brute force / credential stuffing | Rate limits, lockout, breached-password check, MFA |

### Known residual risks (documented, not hidden)

- No RLS at MVP — application-layer isolation is the only tenant boundary until
  Phase 7. Mitigated by the automated isolation suite; accepted consciously.
- Virus scanning is asynchronous — a file is unavailable, not blocked at upload.
- Super Admin impersonation is a real privilege; it is audited and requires a
  reason, but it is a trusted-insider exposure by construction.
