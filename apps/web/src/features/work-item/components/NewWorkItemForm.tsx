"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import {
  CustomFieldInputs,
  initialValues,
} from "@/features/custom-fields/CustomFieldInputs";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { createWorkItem } from "../actions";

type Option = { id: string; label: string };

/**
 * Creating work (docs/08 §4).
 *
 * Until this existed, every work item in this product came from the seed or
 * from curl: `POST /work-items` had been complete and tested since Phase 3 with
 * nothing calling it, hidden behind the GET on the same path. It is the largest
 * thing this codebase's oldest defect ever concealed.
 *
 * Three decisions worth keeping:
 *
 *   1. **The assignee is on this form, not on a screen after it.** The API
 *      accepts `assignee_id` at creation for the reason docs/08 §4 names:
 *      create-then-assign is the most common unnecessary click in tools of this
 *      kind, and an item created with nobody on it waits to be noticed.
 *   2. **Nothing is defaulted that the API defaults.** Type, priority and the
 *      initial state are the workflow's business; a client that posts its own
 *      idea of them is a second copy of a rule, and copies drift. Blank fields
 *      are simply not sent.
 *   3. **The dates are not policed here.** `due_at` must not precede
 *      `start_date` — a CHECK constraint says so, the API says so, and this
 *      form carries their refusal back instead of restating it. The one thing
 *      it does do is give the date inputs a `min`, so the common mistake is
 *      harder to make than to correct.
 *
 * A whole page rather than a dialog: it renders complete on the server with the
 * projects and people it needs, it survives a reload, and it is a link — which
 * means the board's "New work item" button can carry the project with it
 * instead of asking a question the page it came from already answered.
 */
export function NewWorkItemForm({
  projects,
  people,
  defaultProjectId,
  projectKey,
  types,
  priorities,
  customFields,
}: {
  projects: Option[];
  people: Option[];
  /** Pre-answered when the form was opened from a project. */
  defaultProjectId?: string;
  /** The key of that project, so its board can be revalidated by route. */
  projectKey?: string;
  types: string[];
  priorities: string[];
  /** The organization's own fields, blank (ADR 0038). */
  customFields: CustomFieldAnswer[];
}) {
  const router = useRouter();

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [type, setType] = useState("");
  const [projectId, setProjectId] = useState(defaultProjectId ?? "");
  const [priority, setPriority] = useState("");
  const [startDate, setStartDate] = useState("");
  const [dueAt, setDueAt] = useState("");
  const [estimate, setEstimate] = useState("");
  const [assigneeId, setAssigneeId] = useState("");
  const [reviewerId, setReviewerId] = useState("");
  const [custom, setCustom] = useState<Record<string, string>>(() => initialValues(customFields));

  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | undefined>(undefined);
  const [submitting, startTransition] = useTransition();

  const titleId = useId();
  const descriptionId = useId();
  const typeId = useId();
  const projectId_ = useId();
  const priorityId = useId();
  const startId = useId();
  const dueId = useId();
  const estimateId = useId();
  const assigneeId_ = useId();
  const reviewerId_ = useId();

  const submit = () =>
    startTransition(async () => {
      const result = await createWorkItem(
        {
          title,
          description,
          type,
          project_id: projectId,
          priority,
          start_date: startDate,
          // A date input yields a plain date; the column is a timestamp, and
          // "due on the 9th" means the end of the 9th, not the moment it began.
          due_at: dueAt === "" ? "" : `${dueAt}T17:00:00`,
          estimate_hours: estimate,
          assignee_id: assigneeId,
          reviewer_id: reviewerId,
          // Only the answered ones. A blank required field has to arrive as an
          // absence, so the API's refusal names it rather than complaining
          // about the shape of an empty string.
          custom_fields: Object.fromEntries(
            Object.entries(custom).filter(([, value]) => value !== ""),
          ),
        },
        projectKey,
      );

      setError(result.error);
      setRequestId(result.requestId);

      // Straight to the thing that was made. A create form that clears itself
      // and stays put leaves people wondering whether it worked, and the
      // answer — the item, with its reference — is one navigation away.
      if (result.error === null && result.reference !== undefined) {
        router.push(`/work/${result.reference}`);
      }
    });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <Panel
        id="new-work-item"
        title="Work item"
        description="Only the title is required. Everything else can be set later, from the item itself."
        footer={
          <div className="space-y-2">
            <div className="flex items-center gap-3">
              <Button
                type="submit"
                variant="primary"
                disabled={submitting || title.trim().length < 2}
              >
                {submitting ? "Creating…" : "Create work item"}
              </Button>

              <Button
                type="button"
                variant="ghost"
                disabled={submitting}
                onClick={() => router.back()}
              >
                Cancel
              </Button>
            </div>

            {error && (
              // The server's own message, and its request id: "why was this
              // refused" is answered by the rule that refused it, not by a
              // generic apology.
              <p role="alert" className="text-caption text-s-danger">
                {error}
                {requestId !== undefined && (
                  <span className="ml-1.5 font-mono text-n-500">{requestId}</span>
                )}
              </p>
            )}
          </div>
        }
      >
      <div className="space-y-4">
      <Field id={titleId} label="Title">
        <input
          id={titleId}
          type="text"
          value={title}
          onChange={(event) => setTitle(event.target.value)}
          minLength={2}
          maxLength={500}
          required
          autoFocus
          placeholder="What needs doing?"
          className={INPUT}
        />
      </Field>

      <Field id={descriptionId} label="Description" hint="Markdown, and optional.">
        <textarea
          id={descriptionId}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          rows={4}
          maxLength={20000}
          className={`${INPUT} resize-y`}
        />
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id={projectId_}
          label="Project"
          hint="Optional: work with no project is private to the people involved in it."
        >
          <select
            id={projectId_}
            value={projectId}
            onChange={(event) => setProjectId(event.target.value)}
            className={INPUT}
          >
            <option value="">No project</option>
            {projects.map((project) => (
              <option key={project.id} value={project.id}>
                {project.label}
              </option>
            ))}
          </select>
        </Field>

        <Field id={typeId} label="Type">
          <select
            id={typeId}
            value={type}
            onChange={(event) => setType(event.target.value)}
            className={INPUT}
          >
            {/* Blank is not "none": it is "let the API decide", which is where
                the default lives. */}
            <option value="">Default</option>
            {types.map((value) => (
              <option key={value} value={value}>
                {label(value)}
              </option>
            ))}
          </select>
        </Field>

        <Field id={priorityId} label="Priority">
          <select
            id={priorityId}
            value={priority}
            onChange={(event) => setPriority(event.target.value)}
            className={INPUT}
          >
            <option value="">Default</option>
            {priorities.map((value) => (
              <option key={value} value={value}>
                {label(value)}
              </option>
            ))}
          </select>
        </Field>

        <Field id={estimateId} label="Estimate" hint="Hours.">
          <input
            id={estimateId}
            type="number"
            inputMode="decimal"
            step="0.25"
            min="0"
            max="9999"
            value={estimate}
            onChange={(event) => setEstimate(event.target.value)}
            placeholder="0"
            className={INPUT}
          />
        </Field>

        <Field id={startId} label="Start">
          <input
            id={startId}
            type="date"
            value={startDate}
            onChange={(event) => setStartDate(event.target.value)}
            className={INPUT}
          />
        </Field>

        <Field id={dueId} label="Due">
          <input
            id={dueId}
            type="date"
            value={dueAt}
            // The database refuses a due date before its start date, so the
            // picker refuses it too: the common mistake becomes unreachable
            // rather than merely rejected.
            min={startDate === "" ? undefined : startDate}
            onChange={(event) => setDueAt(event.target.value)}
            className={INPUT}
          />
        </Field>

        <Field id={assigneeId_} label="Assignee" hint="Who does it.">
          <select
            id={assigneeId_}
            value={assigneeId}
            onChange={(event) => setAssigneeId(event.target.value)}
            className={INPUT}
          >
            <option value="">Nobody yet</option>
            {people.map((person) => (
              <option key={person.id} value={person.id}>
                {person.label}
              </option>
            ))}
          </select>
        </Field>

        <Field id={reviewerId_} label="Reviewer" hint="Who signs it off.">
          <select
            id={reviewerId_}
            value={reviewerId}
            onChange={(event) => setReviewerId(event.target.value)}
            className={INPUT}
          >
            <option value="">Nobody yet</option>
            {people.map((person) => (
              <option key={person.id} value={person.id}>
                {person.label}
              </option>
            ))}
          </select>
        </Field>
      </div>

      {customFields.length > 0 && (
        // Last, under its own heading: these are what THIS organization adds,
        // and a required one of them is the only reason a create can now be
        // refused for a field that does not exist in another installation.
        <div className="mt-4 space-y-3 border-t border-n-100 pt-4">
          <h2 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
            Fields for this organization
          </h2>

          <CustomFieldInputs
            fields={customFields}
            values={custom}
            idPrefix="new-cf"
            onChange={(key, value) => setCustom((current) => ({ ...current, [key]: value }))}
          />
        </div>
      )}

      </div>
      </Panel>
    </form>
  );
}

/** `approval_work` is not a word. The API's vocabulary is not the user's. */
function label(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, " ");
}
