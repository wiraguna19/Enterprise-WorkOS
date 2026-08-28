/**
 * Frontend verification harness — NOT part of the application.
 *
 * Serves the three Phase 2 endpoints the web app consumes, in the exact
 * envelope defined in docs/05 §3, reading the real seeded PostgreSQL data.
 *
 * It exists so the Next.js app can be rendered and screenshotted in
 * environments where the PHP dependencies cannot be installed. The real API is
 * apps/api; nothing here is ever deployed, and it implements no authorization
 * whatsoever — it trusts any bearer token, because the thing under test is the
 * rendering, not the auth.
 *
 * Run: node infra/docker/mock-api.mjs
 */

import { createServer } from "node:http";
import pg from "pg";

const pool = new pg.Pool({
  host: "127.0.0.1",
  user: "workos",
  password: "workos",
  database: "workos",
});

const ACME = "01900000-0000-7000-8000-0000000000ac";

const envelope = (data, meta = {}) => ({
  data,
  meta: { ...meta, request_id: "req_mock" },
});

const errorEnvelope = (code, message, status) => ({
  status,
  body: { error: { code, message, request_id: "req_mock" } },
});

async function currentUser(email = "rina@acme.test") {
  const { rows } = await pool.query(
    `SELECT u.id, u.name, u.email, u.timezone,
            m.id AS membership_id, m.status, m.joined_at,
            ep.job_title,
            o.id AS org_id, o.name AS org_name, o.slug
       FROM users u
       JOIN memberships m ON m.user_id = u.id AND m.status = 'active'
       JOIN organizations o ON o.id = m.organization_id
  LEFT JOIN employee_profiles ep ON ep.membership_id = m.id
      WHERE lower(u.email) = lower($1)`,
    [email],
  );

  if (rows.length === 0) return null;
  const row = rows[0];

  const { rows: perms } = await pool.query(
    `SELECT DISTINCT p.key
       FROM membership_roles mr
       JOIN role_permissions rp ON rp.role_id = mr.role_id
       JOIN permissions p ON p.id = rp.permission_id
      WHERE mr.membership_id = $1`,
    [row.membership_id],
  );

  return {
    user: { id: row.id, name: row.name, email: row.email, timezone: row.timezone },
    membership: { id: row.membership_id, status: row.status, job_title: row.job_title },
    organization: { id: row.org_id, name: row.org_name, slug: row.slug },
    permissions: perms.map((p) => p.key),
    session: { id: "mock", expires_at: new Date(Date.now() + 864e5).toISOString() },
  };
}


/** One place that shapes a work item, so every endpoint returns the same thing. */
async function workItemPayload(row) {
  const { rows: assignees } = await pool.query(
    `SELECT a.id, a.membership_id, a.role, a.accepted_at, u.name
       FROM work_item_assignments a
       JOIN memberships m ON m.id = a.membership_id
       JOIN users u ON u.id = m.user_id
      WHERE a.work_item_id = $1 AND a.unassigned_at IS NULL`,
    [row.id],
  );

  const overdue =
    row.due_at !== null &&
    new Date(row.due_at) < new Date() &&
    !["done", "cancelled"].includes(row.state_category);

  return {
    id: row.id,
    type: row.type,
    reference: row.reference,
    title: row.title,
    state: {
      id: row.s_id ?? row.workflow_state_id,
      key: row.s_key,
      label: row.s_label,
      category: row.s_cat,
      color: row.s_color,
    },
    state_category: row.state_category,
    priority: row.priority,
    start_date: row.start_date,
    due_at: row.due_at,
    is_overdue: overdue,
    estimate_hours: row.estimate_hours === null ? null : Number(row.estimate_hours),
    position: Number(row.position),
    project: row.p_key ? { id: row.project_id, key: row.p_key, name: row.p_name } : null,
    parent_id: row.parent_id,
    assignees: assignees.map((a) => ({
      assignment_id: a.id,
      membership_id: a.membership_id,
      name: a.name,
      avatar_url: null,
      role: a.role,
      accepted: a.accepted_at !== null,
    })),
    subtask_count: Number(row.subtask_count ?? 0),
    completed_at: row.completed_at,
    created_at: row.created_at,
    lock_version: row.lock_version,
    permissions: {
      update: true, delete: false, assign: true,
      transition: true, submit: true, comment: true,
    },
  };
}

/**
 * The project visibility predicate, mirrored from ProjectModel::scopeVisibleTo.
 * Kept as a database function so the harness cannot accidentally show more than
 * the real API would.
 */
async function ensureVisibilityFunction() {
  await pool.query(`
    CREATE OR REPLACE FUNCTION p_visible(actor uuid)
    RETURNS TABLE (id uuid) AS $fn$
      SELECT p.id FROM projects p
       WHERE p.deleted_at IS NULL
         AND (
              (p.visibility = 'internal')
           OR p.owner_membership_id = actor
           OR EXISTS (
                SELECT 1 FROM project_members pm
                 WHERE pm.project_id = p.id AND pm.removed_at IS NULL
                   AND (pm.membership_id = actor
                     OR pm.team_id IN (SELECT team_id FROM team_members
                                        WHERE membership_id = actor AND left_at IS NULL)))
         );
    $fn$ LANGUAGE sql STABLE;
  `);
}

const routes = {
  "POST /auth/login": async (body) => {
    const me = await currentUser(body.email);

    if (!me || body.password !== "password") {
      return errorEnvelope(
        "auth.invalid_credentials",
        "These credentials do not match our records.",
        401,
      );
    }

    return {
      status: 200,
      body: envelope({
        token: `mock|${me.user.email}`,
        expires_at: me.session.expires_at,
        user: me.user,
        organization: me.organization,
      }),
    };
  },

  "GET /auth/me": async (_body, token) => {
    const me = await currentUser(token?.split("|")[1] ?? "rina@acme.test");

    return me
      ? { status: 200, body: envelope(me) }
      : errorEnvelope("auth.unauthenticated", "Authentication is required.", 401);
  },

  "GET /teams": async () => {
    const { rows } = await pool.query(
      `SELECT t.id, t.name, t.key, d.name AS department_name,
              (SELECT count(*) FROM team_members tm
                WHERE tm.team_id = t.id AND tm.left_at IS NULL) AS member_count
         FROM teams t
    LEFT JOIN departments d ON d.id = t.department_id
        WHERE t.organization_id = $1 AND t.archived_at IS NULL
        ORDER BY t.name`,
      [ACME],
    );

    return {
      status: 200,
      body: envelope(
        rows.map((r) => ({
          id: r.id,
          type: "team",
          name: r.name,
          key: r.key,
          department: r.department_name ? { name: r.department_name } : null,
          member_count: Number(r.member_count),
          permissions: { update: true, manage_members: true, delete: false },
        })),
      ),
    };
  },


  // ── Phase 3 ────────────────────────────────────────────────────────────
  // Mirrors the shape of the real endpoints, including the visibility
  // predicate, so the UI is exercised against data the API would actually
  // return rather than a convenient fiction.

  "GET /me/work/counts": async (_body, token) => {
    const me = await currentUser(token?.split("|")[1] ?? "rina@acme.test");
    const { rows } = await pool.query(
      `SELECT
         count(*) FILTER (WHERE wi.due_at < now() AND wi.state_category NOT IN ('done','cancelled')) AS overdue,
         count(*) FILTER (WHERE wi.due_at::date = current_date AND wi.state_category NOT IN ('done','cancelled')) AS due_today,
         count(*) FILTER (WHERE wi.state_category NOT IN ('done','cancelled')) AS open,
         count(*) FILTER (WHERE wi.state_category IN ('in_review','blocked')) AS waiting
       FROM work_items wi
       JOIN work_item_assignments a
         ON a.work_item_id = wi.id AND a.unassigned_at IS NULL AND a.role = 'assignee'
      WHERE a.membership_id = $1 AND wi.deleted_at IS NULL`,
      [me.membership.id],
    );
    const r = rows[0];
    return { status: 200, body: envelope({
      overdue: Number(r.overdue), due_today: Number(r.due_today),
      open: Number(r.open), waiting_on_others: Number(r.waiting),
    }) };
  },

  "GET /me/work": async (_body, token, url) => {
    const me = await currentUser(token?.split("|")[1] ?? "rina@acme.test");
    const view = url.searchParams.get("view") ?? "today";

    const where = {
      today: "wi.due_at < (current_date + 1) AND wi.state_category NOT IN ('done','cancelled')",
      upcoming: "wi.due_at > (current_date + 1) AND wi.due_at <= now() + interval '14 days' AND wi.state_category NOT IN ('done','cancelled')",
      overdue: "wi.due_at < now() AND wi.state_category NOT IN ('done','cancelled')",
      assigned: "wi.state_category NOT IN ('done','cancelled')",
      waiting_on_others: "wi.state_category IN ('in_review','blocked')",
      completed: "wi.state_category = 'done' AND wi.completed_at >= now() - interval '30 days'",
    }[view] ?? "true";

    const { rows } = await pool.query(
      `SELECT wi.*, s.key AS s_key, s.label AS s_label, s.category AS s_cat, s.color AS s_color,
              p.key AS p_key, p.name AS p_name,
              (SELECT count(*) FROM work_items c WHERE c.parent_id = wi.id) AS subtask_count
         FROM work_items wi
         JOIN workflow_states s ON s.id = wi.workflow_state_id
    LEFT JOIN projects p ON p.id = wi.project_id
         JOIN work_item_assignments a
           ON a.work_item_id = wi.id AND a.unassigned_at IS NULL AND a.role = 'assignee'
        WHERE a.membership_id = $1 AND wi.deleted_at IS NULL AND ${where}
        ORDER BY wi.due_at NULLS LAST, wi.reference
        LIMIT 100`,
      [me.membership.id],
    );

    return { status: 200, body: envelope(await Promise.all(rows.map(workItemPayload)), {
      pagination: { per_page: 100, next_cursor: null, has_more: false }, view,
    }) };
  },

  "GET /projects": async (_body, token) => {
    const me = await currentUser(token?.split("|")[1] ?? "rina@acme.test");
    const { rows } = await pool.query(
      `SELECT p.*,
              (SELECT count(*) FROM project_members m WHERE m.project_id = p.id AND m.removed_at IS NULL) AS member_count,
              (SELECT count(*) FROM work_items w WHERE w.project_id = p.id AND w.deleted_at IS NULL
                 AND w.state_category NOT IN ('done','cancelled')) AS open_work_count,
              (SELECT count(*) FROM work_items w WHERE w.project_id = p.id AND w.deleted_at IS NULL
                 AND w.state_category NOT IN ('done','cancelled') AND w.due_at < now()) AS overdue_work_count
         FROM p_visible($1) v JOIN projects p ON p.id = v.id
        WHERE p.archived_at IS NULL
        ORDER BY p.name`,
      [me.membership.id],
    );

    return { status: 200, body: envelope(rows.map((p) => ({
      id: p.id, key: p.key, name: p.name, status: p.status, priority: p.priority,
      visibility: p.visibility, start_date: p.start_date, end_date: p.end_date,
      progress: Number(p.progress_cache), progress_as_of: p.progress_cached_at,
      member_count: Number(p.member_count),
      open_work_count: Number(p.open_work_count),
      overdue_work_count: Number(p.overdue_work_count),
      archived: p.archived_at !== null, lock_version: p.lock_version,
      permissions: { update: true, delete: false, archive: true, manage_members: true, create_work: true },
    }))) };
  },

  "GET /projects/:key/board": async (_body, token, url, key) => {
    const { rows: projectRows } = await pool.query(
      `SELECT * FROM projects WHERE key = $1 AND organization_id = $2`, [key, ACME],
    );
    if (projectRows.length === 0) {
      return errorEnvelope("resource.not_found", "Not found.", 404);
    }
    const project = projectRows[0];

    const { rows: states } = await pool.query(
      `SELECT id, key, label, category, color, position FROM workflow_states
        WHERE workflow_id = $1 ORDER BY position`, [project.workflow_id],
    );

    const { rows: items } = await pool.query(
      `SELECT wi.*, s.key AS s_key, s.label AS s_label, s.category AS s_cat, s.color AS s_color,
              p.key AS p_key, p.name AS p_name,
              (SELECT count(*) FROM work_items c WHERE c.parent_id = wi.id) AS subtask_count
         FROM work_items wi
         JOIN workflow_states s ON s.id = wi.workflow_state_id
    LEFT JOIN projects p ON p.id = wi.project_id
        WHERE wi.project_id = $1 AND wi.deleted_at IS NULL
        ORDER BY wi.position`, [project.id],
    );

    const payloads = await Promise.all(items.map(workItemPayload));

    return { status: 200, body: envelope({
      project: {
        id: project.id, key: project.key, name: project.name, status: project.status,
        priority: project.priority, visibility: project.visibility,
        start_date: project.start_date, end_date: project.end_date,
        progress: Number(project.progress_cache), progress_as_of: project.progress_cached_at,
        archived: false, lock_version: project.lock_version,
        permissions: { update: true, delete: false, archive: true, manage_members: true, create_work: true },
      },
      columns: states.map((state) => ({
        state,
        items: payloads.filter((i) => i.state?.id === state.id),
      })),
    }) };
  },

  "GET /work-items/:reference": async (_body, token, url, reference) => {
    const { rows } = await pool.query(
      `SELECT wi.*, s.id AS s_id, s.key AS s_key, s.label AS s_label, s.category AS s_cat, s.color AS s_color,
              p.key AS p_key, p.name AS p_name,
              (SELECT count(*) FROM work_items c WHERE c.parent_id = wi.id) AS subtask_count
         FROM work_items wi
         JOIN workflow_states s ON s.id = wi.workflow_state_id
    LEFT JOIN projects p ON p.id = wi.project_id
        WHERE upper(wi.reference) = upper($1) AND wi.organization_id = $2 AND wi.deleted_at IS NULL`,
      [reference, ACME],
    );
    if (rows.length === 0) return errorEnvelope("resource.not_found", "Resource not found.", 404);

    const payload = await workItemPayload(rows[0]);
    payload.description = rows[0].description;

    return { status: 200, body: envelope(payload) };
  },

  "GET /work-items/:reference/comments": async (_body, token, url, reference) => {
    const { rows } = await pool.query(
      `SELECT c.*, u.name AS author_name, m.id AS author_membership
         FROM comments c
         JOIN work_items wi ON wi.id = c.commentable_id AND c.commentable_type = 'work_item'
    LEFT JOIN memberships m ON m.id = c.author_membership_id
    LEFT JOIN users u ON u.id = m.user_id
        WHERE upper(wi.reference) = upper($1) AND c.deleted_at IS NULL
        ORDER BY c.created_at`, [reference],
    );

    return { status: 200, body: envelope(rows.map((c) => ({
      id: c.id, type: "comment",
      author: { membership_id: c.author_membership, name: c.author_name, avatar_url: null },
      body_html: c.body_html, body_markdown: c.body_markdown,
      parent_id: c.parent_id, edited: c.edited_at !== null, created_at: c.created_at,
    }))) };
  },

  "GET /work-items/:reference/assignments": async (_body, token, url, reference) => {
    const { rows } = await pool.query(
      `SELECT a.*, u.name AS person, bu.name AS assigned_by
         FROM work_item_assignments a
         JOIN work_items wi ON wi.id = a.work_item_id
         JOIN memberships m ON m.id = a.membership_id
         JOIN users u ON u.id = m.user_id
    LEFT JOIN memberships bm ON bm.id = a.assigned_by_membership_id
    LEFT JOIN users bu ON bu.id = bm.user_id
        WHERE upper(wi.reference) = upper($1)
        ORDER BY a.assigned_at`, [reference],
    );

    return { status: 200, body: envelope(rows.map((a) => ({
      id: a.id, role: a.role, person: a.person, assigned_by: a.assigned_by,
      assigned_at: a.assigned_at, accepted_at: a.accepted_at,
      unassigned_at: a.unassigned_at, reason: a.unassigned_reason,
      active: a.unassigned_at === null,
    }))) };
  },

  // ── Phase 4 ────────────────────────────────────────────────────────────
  // The workflow graph, the review queue, and the inbox. All three read the
  // seeded rows rather than a fixture, so the UI is exercised against the same
  // data the proofs in verify-workflow-constraints.sql assert on.

  "GET /work-items/:reference/available-transitions": async (_b, token, _url, reference) => {
    const me = await currentUser(token?.split("|")[1] ?? "rina@acme.test");

    const { rows: itemRows } = await pool.query(
      `SELECT id, workflow_id, workflow_state_id, state_category, created_by_membership_id
         FROM work_items WHERE upper(reference) = upper($1) AND organization_id = $2`,
      [reference, ACME],
    );
    if (itemRows.length === 0) return errorEnvelope("resource.not_found", "Not found.", 404);
    const item = itemRows[0];

    // The actor's roles ON THIS ITEM. Guards are role-relative, so holding
    // approval.decide is not the same as having been asked to review this.
    const { rows: roleRows } = await pool.query(
      `SELECT role FROM work_item_assignments
        WHERE work_item_id = $1 AND membership_id = $2 AND unassigned_at IS NULL`,
      [item.id, me.membership.id],
    );
    const roles = roleRows.map((r) => r.role);
    if (item.created_by_membership_id === me.membership.id) roles.push("creator");

    const { rows: transitions } = await pool.query(
      `SELECT t.id, t.label, t.guard, t.requires_comment, t.from_state_id,
              s.id AS to_id, s.key AS to_key, s.label AS to_label,
              s.category AS to_category, s.color AS to_color
         FROM workflow_transitions t
         JOIN workflow_states s ON s.id = t.to_state_id
        WHERE t.workflow_id = $1
          AND (t.from_state_id = $2 OR t.from_state_id IS NULL)
          AND t.to_state_id <> $2
        ORDER BY t.position`,
      [item.workflow_id, item.workflow_state_id],
    );

    // Mirrors TransitionService::guardFailure. Kept in step deliberately: a
    // harness that is more permissive than the real guard would let a broken
    // picker screenshot as if it worked.
    const guardFailure = (guard, label) => {
      const g = guard ?? {};

      // Role before permission, matching TransitionService: "only the reviewer
      // can approve" explains the workflow, while a missing permission key
      // explains the RBAC configuration. The first is actionable.
      if (Array.isArray(g.actor_is) && !g.actor_is.some((r) => roles.includes(r))) {
        return `Only the ${g.actor_is.join(" or ")} can "${label}".`;
      }
      if (g.permission && !me.permissions.includes(g.permission)) {
        return `You do not have permission to "${label}".`;
      }
      return null;
    };

    return { status: 200, body: envelope({
      current: { id: item.workflow_state_id, category: item.state_category },
      transitions: transitions.map((t) => {
        const blocked = guardFailure(t.guard, t.label);
        return {
          id: t.id,
          label: t.label,
          to_state: {
            id: t.to_id, key: t.to_key, label: t.to_label,
            category: t.to_category, color: t.to_color,
          },
          requires_comment: t.requires_comment,
          // A move available from ANY state is an escape hatch (blocked,
          // cancelled), never the recommended next step. See
          // TransitionService::availableFrom.
          is_escape_hatch: t.from_state_id === null,
          available: blocked === null,
          blocked_reason: blocked,
        };
      }),
    }) };
  },

  "GET /approvals": async (_b, token, url) => {
    const me = await currentUser(token?.split("|")[1] ?? "ahmad@acme.test");
    const role = url.searchParams.get("role") ?? "reviewer";
    const status = url.searchParams.get("status") ?? "pending";

    const scope = role === "reviewer"
      ? `EXISTS (SELECT 1 FROM approval_approvers aa
                  WHERE aa.approval_id = a.id AND aa.membership_id = $1)`
      : `a.requested_by_membership_id = $1`;

    const { rows } = await pool.query(
      `SELECT a.*, u.name AS requester_name,
              wi.reference, wi.title, wi.priority, wi.state_category, wi.due_at
         FROM approvals a
         JOIN memberships rm ON rm.id = a.requested_by_membership_id
         JOIN users u ON u.id = rm.user_id
    LEFT JOIN work_items wi ON wi.id = a.subject_id AND a.subject_type = 'work_item'
        WHERE a.organization_id = $2 AND a.status = $3 AND ${scope}
        ORDER BY a.submitted_at`,
      [me.membership.id, ACME, status],
    );

    const decisions = rows.length === 0 ? { rows: [] } : await pool.query(
      `SELECT d.*, u.name AS reviewer_name
         FROM approval_decisions d
         JOIN memberships m ON m.id = d.reviewer_membership_id
         JOIN users u ON u.id = m.user_id
        WHERE d.approval_id = ANY($1::uuid[]) ORDER BY d.decided_at`,
      [rows.map((r) => r.id)],
    );

    const approvers = rows.length === 0 ? { rows: [] } : await pool.query(
      `SELECT aa.approval_id, aa.membership_id, u.name
         FROM approval_approvers aa
         JOIN memberships m ON m.id = aa.membership_id
         JOIN users u ON u.id = m.user_id
        WHERE aa.approval_id = ANY($1::uuid[])`,
      [rows.map((r) => r.id)],
    );

    return { status: 200, body: envelope(rows.map((a) => ({
      id: a.id,
      status: a.status,
      policy: a.policy,
      required_approvals: a.required_approvals,
      submission_note: a.submission_note,
      submitted_at: a.submitted_at,
      resolved_at: a.resolved_at,
      requester: { membership_id: a.requested_by_membership_id, name: a.requester_name },
      subject: a.reference
        ? { type: "work_item", reference: a.reference, title: a.title,
            priority: a.priority, state_category: a.state_category, due_at: a.due_at }
        : null,
      approvers: approvers.rows
        .filter((r) => r.approval_id === a.id)
        .map((r) => ({ membership_id: r.membership_id, name: r.name })),
      // Every decision, not just the last: a resolved approval that shows only
      // the final verdict hides that the work was bounced back once.
      decisions: decisions.rows
        .filter((r) => r.approval_id === a.id)
        .map((r) => ({
          id: r.id, decision: r.decision, comment: r.comment,
          decided_at: r.decided_at, reviewer: r.reviewer_name,
        })),
      permissions: {
        decide: approvers.rows.some(
          (r) => r.approval_id === a.id && r.membership_id === me.membership.id,
        ) && a.status === "pending",
        withdraw: a.requested_by_membership_id === me.membership.id && a.status === "pending",
      },
    }))) };
  },

  "GET /me/approvals": async (body, token, url) => routes["GET /approvals"](body, token, url),

  "GET /notifications": async (_b, token, url) => {
    const me = await currentUser(token?.split("|")[1] ?? "ahmad@acme.test");
    const unreadOnly = url.searchParams.get("unread") === "true";

    const { rows } = await pool.query(
      `SELECT n.*, u.name AS actor_name
         FROM notifications n
    LEFT JOIN memberships m ON m.id = n.actor_membership_id
    LEFT JOIN users u ON u.id = m.user_id
        WHERE n.organization_id = $1 AND n.membership_id = $2
          AND n.archived_at IS NULL
          AND ($3::bool IS NOT TRUE OR n.read_at IS NULL)
        ORDER BY n.created_at DESC
        LIMIT 50`,
      [ACME, me.membership.id, unreadOnly],
    );

    return { status: 200, body: envelope(rows.map((n) => ({
      id: n.id,
      type: n.type,
      subject_type: n.subject_type,
      subject_id: n.subject_id,
      // The payload is a snapshot taken at send time, so the inbox reads
      // correctly even after the item is renamed (docs/03 §5).
      payload: n.payload,
      actor: n.actor_membership_id ? { name: n.actor_name ?? n.payload?.actor_name } : null,
      read: n.read_at !== null,
      created_at: n.created_at,
    }))) };
  },

  "GET /notifications/unread-count": async (_b, token) => {
    const me = await currentUser(token?.split("|")[1] ?? "ahmad@acme.test");
    const { rows } = await pool.query(
      `SELECT count(*) AS unread FROM notifications
        WHERE membership_id = $1 AND read_at IS NULL AND archived_at IS NULL`,
      [me.membership.id],
    );
    return { status: 200, body: envelope({ unread: Number(rows[0].unread) }) };
  },

  "GET /notifications/preferences": async (_b, token) => {
    const me = await currentUser(token?.split("|")[1] ?? "ahmad@acme.test");
    const { rows } = await pool.query(
      `SELECT type, in_app, email, digest FROM notification_preferences
        WHERE membership_id = $1 ORDER BY type`,
      [me.membership.id],
    );
    return { status: 200, body: envelope(rows) };
  },

  "GET /people": async () => {
    const { rows } = await pool.query(
      `SELECT m.id, m.status, m.joined_at,
              u.name, u.email,
              ep.job_title, ep.employment_type, ep.weekly_capacity_hours,
              d.id AS dept_id, d.name AS dept_name
         FROM memberships m
         JOIN users u ON u.id = m.user_id
    LEFT JOIN employee_profiles ep ON ep.membership_id = m.id
    LEFT JOIN departments d ON d.id = ep.department_id
        WHERE m.organization_id = $1 AND m.status = 'active'
        ORDER BY u.name`,
      [ACME],
    );

    return {
      status: 200,
      body: envelope(
        rows.map((r) => ({
          id: r.id,
          type: "person",
          name: r.name,
          email: r.email,
          status: r.status,
          joined_at: r.joined_at,
          job_title: r.job_title,
          employment_type: r.employment_type,
          weekly_capacity_hours: r.weekly_capacity_hours,
          department: r.dept_id ? { id: r.dept_id, name: r.dept_name } : null,
          permissions: { update: true, deactivate: false, view_workload: true },
        })),
        { pagination: { per_page: 100, next_cursor: null, has_more: false } },
      ),
    };
  },
};

async function handleRequest(req, res) {
  const url = new URL(req.url, "http://localhost");
  const path = url.pathname.replace(/^\/api\/v1/, "");
  const key = `${req.method} ${path}`;

  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString()) : {};

  const token = req.headers.authorization?.replace("Bearer ", "");

  // Exact match first, then a single-parameter pattern (:key / :reference).
  let handler = routes[key];
  const params = [];

  if (!handler) {
    for (const [pattern, fn] of Object.entries(routes)) {
      if (!pattern.includes(":")) continue;

      const [method, template] = pattern.split(" ");
      if (method !== req.method) continue;

      const regex = new RegExp("^" + template.replace(/:[^/]+/g, "([^/]+)") + "$");
      const match = path.match(regex);

      if (match) {
        handler = fn;
        params.push(...match.slice(1));
        break;
      }
    }
  }

  if (!handler) {
    res.writeHead(404, { "Content-Type": "application/json" });
    res.end(JSON.stringify(errorEnvelope("resource.not_found", "Not found.", 404).body));

    return;
  }

  try {
    const result = await handler(body, token, url, ...params);
    res.writeHead(result.status, { "Content-Type": "application/json" });
    res.end(JSON.stringify(result.body));
  } catch (error) {
    res.writeHead(500, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ error: { code: "server.error", message: String(error) } }));
  }
}
await ensureVisibilityFunction();

createServer(handleRequest).listen(8000, () => console.log("mock api on :8000"));
