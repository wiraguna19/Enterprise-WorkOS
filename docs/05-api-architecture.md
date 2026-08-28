# 05 — API Architecture

---

## 1. Principles

1. **The API models the domain, not the screen.** There is no `/dashboard`
   endpoint shaped like the dashboard component. Composition for the UI happens
   in the Next.js BFF layer (`01` §2).
2. **Resources are nouns; state changes that are not simple field edits are
   sub-resource actions.** `POST /work-items/{id}/submit` exists because
   submitting is a domain operation with rules and side effects, not a
   `PATCH {status: "in_review"}`. Pretending otherwise pushes workflow logic
   into the client.
3. **Versioned from the first commit.** `/api/v1`. Adding a version later means
   retrofitting routing, docs, and clients.
4. **Every response is a Resource class.** No model is ever serialized directly —
   that is how `password_hash` ends up in a payload.
5. **Consistent envelope, consistent errors, consistent pagination.** A client
   that can parse one endpoint can parse all of them.

---

## 2. Endpoint map

```text
AUTH
  POST   /auth/login                       POST   /auth/logout
  POST   /auth/refresh                     GET    /auth/me
  POST   /auth/forgot-password             POST   /auth/reset-password
  POST   /auth/mfa/challenge               POST   /auth/mfa/verify
  GET    /auth/sessions                    DELETE /auth/sessions/{id}

ORGANIZATION
  GET    /organizations/current            PATCH  /organizations/current
  GET|POST      /departments               GET|PATCH|DELETE /departments/{id}
  GET|POST      /teams                     GET|PATCH|DELETE /teams/{id}
  GET|POST      /teams/{id}/members        DELETE /teams/{id}/members/{mid}
  GET|POST      /people                    GET|PATCH /people/{id}
  POST   /people/{id}/deactivate           POST   /people/invite
  GET    /people/{id}/workload             GET    /people/{id}/work

ACCESS CONTROL
  GET|POST      /roles                     GET|PATCH|DELETE /roles/{id}
  GET    /permissions                      PUT    /roles/{id}/permissions
  PUT    /people/{id}/roles

PROJECTS
  GET|POST      /projects                  GET|PATCH|DELETE /projects/{id}
  POST   /projects/{id}/archive
  GET|POST      /projects/{id}/members     DELETE /projects/{id}/members/{mid}
  GET|POST      /projects/{id}/milestones
  GET    /projects/{id}/overview           GET    /projects/{id}/activity

WORK ITEMS
  GET|POST      /work-items                GET|PATCH|DELETE /work-items/{id}
  POST   /work-items/{id}/assign           DELETE /work-items/{id}/assignees/{aid}
  POST   /work-items/{id}/accept           POST   /work-items/{id}/transition
  POST   /work-items/{id}/submit           POST   /work-items/{id}/move
  GET    /work-items/{id}/assignments      GET    /work-items/{id}/activity
  GET|POST      /work-items/{id}/dependencies
  GET|POST      /work-items/{id}/children
  POST   /work-items/bulk                  (bounded batch: assign / transition / tag)

  GET    /me/work                          ?view=today|upcoming|overdue|
                                            assigned|created|awaiting_review|completed

WORKFLOW
  GET|POST      /workflows                 GET|PATCH /workflows/{id}
  GET    /workflows/{id}/states            GET    /workflows/{id}/transitions
  GET    /work-items/{id}/available-transitions
  GET|POST      /workflows/{id}/rules      PATCH|DELETE /workflow-rules/{id}

APPROVALS
  GET    /approvals                        GET    /approvals/{id}
  POST   /approvals                        POST   /approvals/{id}/decide
  POST   /approvals/{id}/withdraw
  GET    /me/approvals                     ?role=requester|reviewer

COLLABORATION
  GET|POST      /{subject}/{id}/comments   PATCH|DELETE /comments/{id}
  POST   /files/upload-url                 POST   /files/{id}/complete
  GET|POST      /{subject}/{id}/attachments  DELETE /attachments/{id}
  GET    /attachments/{id}/download        (redirects to signed URL)

NOTIFICATIONS
  GET    /notifications                    POST   /notifications/read
  POST   /notifications/read-all           GET    /notifications/unread-count
  GET|PUT       /notifications/preferences

SEARCH / CALENDAR / INSIGHTS
  GET    /search                           ?q=&types=&limit=
  GET    /calendar                         ?from=&to=&sources=
  GET    /insights/dashboard               ?scope=me|team|organization
  GET    /insights/workload                ?scope=&from=&to=
  GET    /insights/projects/{id}/health
  GET    /reports/{key}                    POST /reports/{key}/export

GOVERNANCE
  GET    /activity                         GET  /audit-logs        (admin only)
  GET|PUT       /settings                  GET|POST /custom-fields
  GET|POST      /tags                      GET|POST /saved-views
```

Note `GET /me/work` and `GET /insights/dashboard`: these are **domain queries
about the current actor**, not UI-shaped endpoints. The distinction matters —
"my work" is a real concept in the domain and the ubiquitous language, so it
earns an endpoint. "The six cards on the manager home screen" does not.

---

## 3. Request and response format

### Success — single resource

```json
{
  "data": {
    "id": "018f...",
    "type": "work_item",
    "reference": "ENG-142",
    "title": "Implement assignment history",
    "state": { "id": "...", "key": "in_progress", "label": "In Progress",
               "category": "in_progress", "color": "amber" },
    "priority": "high",
    "due_at": "2026-09-04T09:00:00Z",
    "project": { "id": "...", "key": "ENG", "name": "Platform" },
    "assignees": [
      { "id": "...", "name": "Sarah Chen", "avatar_url": "...", "role": "assignee" }
    ],
    "permissions": { "update": true, "assign": true, "transition": true, "delete": false },
    "lock_version": 7,
    "created_at": "2026-08-01T02:11:00Z"
  },
  "meta": { "request_id": "req_01J..." }
}
```

**`permissions` on every resource** is deliberate. The client must know which
actions to render without guessing or duplicating the permission rules. The
backend remains the only authority — this block *describes* the server's
decision, it does not delegate it.

### Success — collection

```json
{
  "data": [ ... ],
  "meta": {
    "pagination": { "per_page": 50, "next_cursor": "eyJpZCI6...", "has_more": true },
    "request_id": "req_01J..."
  }
}
```

**Cursor pagination by default**, not offset. Offset pagination on a table
sorted by an updating column produces duplicate and skipped rows while the user
pages — and page 400 of an offset query is a sequential scan. Offset is offered
only where a total count is genuinely required (reports), and there `meta.total`
is an estimate above a threshold.

### Errors

```json
{
  "error": {
    "code": "work_item.invalid_transition",
    "message": "Cannot move from Completed to In Progress.",
    "details": {
      "from": "completed", "to": "in_progress",
      "allowed": ["reopened"]
    },
    "request_id": "req_01J..."
  }
}
```

| Status | Used for |
|---|---|
| 400 | Malformed request |
| 401 | Not authenticated |
| 403 | Authenticated, not permitted |
| 404 | Not found **or not visible to this tenant** (never 403 across tenants — a 403 confirms the resource exists) |
| 409 | Optimistic lock conflict, or state conflict (already approved) |
| 422 | Validation failure — `details` keyed by field |
| 429 | Rate limited, with `Retry-After` |
| 5xx | Never leaks a stack trace; always carries `request_id` |

**`code` is a stable machine-readable string.** Clients branch on `code`, never
on `message` — messages are localised and will change.

---

## 4. Filtering, sorting, sparse fields

One grammar across every collection endpoint:

```text
GET /work-items
  ?filter[project_id]=018f...
  &filter[state_category]=in_progress,in_review
  &filter[assignee_id]=me
  &filter[due_at][lte]=2026-09-01
  &filter[tag]=urgent
  &filter[cf_client]=Acme            custom field, prefix cf_
  &sort=-due_at,position
  &include=assignees,project,state
  &fields[work_item]=id,title,due_at,state
  &limit=50&cursor=...
```

- **Allowed filters, sorts, and includes are declared per endpoint** in a
  whitelist. An unknown key is a 422, not silently ignored — silent ignoring is
  how a client ships a broken filter nobody notices for a month.
- **`include` depth is capped at 2** and each include has a defined eager-load
  path. This is the N+1 firewall.
- `assignee_id=me` resolves server-side; the client never has to know its own ID.

---

## 5. Write semantics

- **`PATCH`, not `PUT`, for updates.** Partial by default; the client sends only
  what changed, which is also what makes the activity-log diff meaningful.
- **`If-Match` / `lock_version`** on updates to concurrency-sensitive resources.
  Missing it is allowed for non-conflicting field edits; a conflict returns 409
  with the current server state so the client can present a real merge.
- **Idempotency keys** on `POST` for creation endpoints:
  `Idempotency-Key: <uuid>` header, stored for 24h keyed by (user, endpoint,
  key). A retried request after a network timeout returns the original result
  instead of creating a duplicate work item.
- **Bulk operations are bounded** (max 100 subjects), transactional per subject
  with a per-subject result array, never all-or-nothing across 100 items:

```json
{ "data": { "succeeded": 97, "failed": 3,
            "results": [ { "id": "...", "ok": false,
                           "error": { "code": "work_item.forbidden" } } ] } }
```

---

## 6. Uploads

Direct-to-storage, never through the API process:

```text
1. POST /files/upload-url   { name, mime, size }
     → server validates mime/size against policy, creates files row (pending),
       returns a short-lived presigned PUT URL
2. Client PUTs the bytes to object storage
3. POST /files/{id}/complete  { checksum }
     → server verifies size/checksum via HEAD, queues virus scan,
       marks status
4. POST /work-items/{id}/attachments { file_id }
```

Reasons: a 200 MB upload does not occupy a PHP worker; the API never buffers
user bytes; MIME and size policy are enforced before a single byte is accepted.
Downloads go the same way — the API returns a short-lived signed URL after an
authorization check, and the bucket is never public.

---

## 7. Rate limiting

| Bucket | Limit |
|---|---|
| `POST /auth/login` | 5 / 15 min per (IP + email), then exponential lockout |
| Password reset | 3 / hour per email |
| Authenticated general | 300 / min per user |
| Write endpoints | 60 / min per user |
| Search | 30 / min per user |
| Export / report generation | 5 / hour per organization |

Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`.

---

## 8. Documentation & contract

- OpenAPI 3.1 generated from attributes on controllers and resources
  (`Scramble`), served at `/api/v1/docs`, and **checked into the repo**.
- CI **fails on an uncommitted spec diff**, which forces the contract change to
  be visible in the pull request rather than discovered by the client team.
- `packages/api-client` regenerates from the spec; the web app's typecheck is
  the contract test.
- Every endpoint has at least one feature test asserting status, shape, and
  authorization — including the negative case.
