"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import type { Project } from "@/features/work-item/types";
import { setProjectArchived, updateProject, type ProjectEdit } from "./actions";

const STATUSES = ["planning", "active", "on_hold", "completed", "cancelled"];
const PRIORITIES = ["low", "medium", "high", "urgent"];

/**
 * Correcting a project (ADR 0040).
 *
 * Three things here are not decoration, and they are the same three the work
 * item form settled on — because the underlying rules are the same:
 *
 *   1. **Only changed fields are sent.** PATCH is what makes the activity
 *      log's diff meaningful; a save that reports every field as touched turns
 *      the history into noise nobody reads.
 *   2. **The version travels with the edit**, and a 409 STOPS. Nothing offers
 *      to force: the other person's edit is not an obstacle.
 *   3. **The key is not here.** It is in every work item reference this project
 *      has ever produced, so changing it is a migration, not an edit — and the
 *      API refuses it by name. A control the API will refuse is a dead control;
 *      not rendering one is cheaper than explaining it.
 *
 * Archiving is a SEPARATE control with its own words, below, for the reason
 * renaming and re-parenting a department are separate: one fixes a spelling,
 * the other takes a project off every board in the organization.
 */
export function EditProjectForm({ project }: { project: Project }) {
  const router = useRouter();

  const [name, setName] = useState(project.name);
  const [description, setDescription] = useState(project.description ?? "");
  const [status, setStatus] = useState(project.status);
  const [priority, setPriority] = useState(project.priority);
  const [visibility, setVisibility] = useState(project.visibility);
  const [startDate, setStartDate] = useState(project.start_date ?? "");
  const [endDate, setEndDate] = useState(project.end_date ?? "");

  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<{ yours: number; current: number } | null>(null);
  const [saving, start] = useTransition();

  const nameId = useId();
  const descriptionId = useId();
  const statusId = useId();
  const priorityId = useId();
  const visibilityId = useId();
  const startId = useId();
  const endId = useId();

  const save = (): void =>
    start(async () => {
      const changes: ProjectEdit = {};

      if (name !== project.name) changes.name = name;
      if (description !== (project.description ?? "")) changes.description = description;
      if (status !== project.status) changes.status = status;
      if (priority !== project.priority) changes.priority = priority;
      if (visibility !== project.visibility) changes.visibility = visibility;

      // A cleared date is `null`, not "": the column is nullable and the
      // validator takes nullable, but an empty string is neither a date nor an
      // absence to it.
      if (startDate !== (project.start_date ?? "")) {
        changes.start_date = startDate === "" ? null : startDate;
      }

      if (endDate !== (project.end_date ?? "")) {
        changes.end_date = endDate === "" ? null : endDate;
      }

      const result = await updateProject(project.key, changes, project.lock_version);

      setError(result.error);
      setConflict(result.conflict ?? null);

      if (result.error === null) router.refresh();
    });

  return (
    <div className="space-y-4">
      <Panel
        id="details"
        title="Details"
        description={
          <>
            The key <code className="font-mono">{project.key}</code> cannot change — it is in
            every work item reference this project has produced.
          </>
        }
        footer={
          <div className="flex flex-wrap items-center gap-3">
            <Button
              variant="primary"
              size="sm"
              disabled={saving || conflict !== null || name.trim().length < 2}
              onClick={save}
            >
              {saving ? "Saving…" : "Save changes"}
            </Button>

            {name.trim().length < 2 && (
              <p role="status" className="text-caption text-n-500">
                A project needs a name of at least two characters.
              </p>
            )}
          </div>
        }
      >
        <div className="space-y-3">
          <Field id={nameId} label="Name">
            <input
              id={nameId}
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={160}
              className={INPUT}
            />
          </Field>

          <Field id={descriptionId} label="Description">
            <textarea
              id={descriptionId}
              rows={4}
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              className={`${INPUT} resize-y`}
            />
          </Field>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field
              id={statusId}
              label="Status"
              hint="How the work is going. Not the same as archived."
            >
              <select
                id={statusId}
                value={status}
                onChange={(event) => setStatus(event.target.value)}
                className={INPUT}
              >
                {STATUSES.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {candidate.replace(/_/g, " ")}
                  </option>
                ))}
              </select>
            </Field>

            <Field id={priorityId} label="Priority">
              <select
                id={priorityId}
                value={priority}
                onChange={(event) => setPriority(event.target.value as Project["priority"])}
                className={INPUT}
              >
                {PRIORITIES.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {candidate}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              id={visibilityId}
              label="Visibility"
              hint="Private is visible to its members only."
            >
              <select
                id={visibilityId}
                value={visibility}
                onChange={(event) => setVisibility(event.target.value as Project["visibility"])}
                className={INPUT}
              >
                <option value="internal">internal</option>
                <option value="private">private</option>
              </select>
            </Field>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <Field id={startId} label="Starts" hint="Empty clears it.">
              <input
                id={startId}
                type="date"
                value={startDate}
                onChange={(event) => setStartDate(event.target.value)}
                className={INPUT}
              />
            </Field>

            <Field id={endId} label="Ends" hint="Cannot be before the start.">
              <input
                id={endId}
                type="date"
                value={endDate}
                onChange={(event) => setEndDate(event.target.value)}
                className={INPUT}
              />
            </Field>
          </div>

          {conflict !== null ? (
            <div role="alert" className="space-y-2 rounded-lg border border-s-active/40 bg-s-active/5 p-3">
              <p className="text-body-sm text-n-900">
                Somebody else saved this project while you had it open — it is now at version{" "}
                {conflict.current}, and you started from {conflict.yours}. Your changes have not
                been saved, and theirs have not been touched.
              </p>
              <Button size="sm" onClick={() => router.refresh()}>
                Reload their version
              </Button>
            </div>
          ) : (
            error !== null && (
              <p role="alert" className="text-caption text-s-danger">
                {error}
              </p>
            )
          )}
        </div>
      </Panel>

      {project.permissions.archive && <ArchiveControl project={project} />}
    </div>
  );
}

/**
 * Archiving, behind a second click, saying what it actually does.
 *
 * It is reversible and nothing is deleted, so the sentence says so — this
 * product's rule is that a control is named for what it DOES, and "Delete"
 * here would promise an erasure the API deliberately refuses.
 */
function ArchiveControl({ project }: { project: Project }) {
  const router = useRouter();
  const [armed, setArmed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [working, start] = useTransition();

  const archived = project.archived;

  const run = (): void =>
    start(async () => {
      const result = await setProjectArchived(project.key, !archived);

      setError(result.error);
      setArmed(false);

      if (result.error === null) router.refresh();
    });

  return (
    <Panel
      id="archive"
      title={archived ? "Archived" : "Archive this project"}
      description={
        archived
          ? "It is off the boards and out of the directory. Its work, hours and history are untouched."
          : "It comes off the boards and out of the directory. Nothing is deleted, and you can bring it back."
      }
      tone={archived ? "default" : "danger"}
    >
      <div className="flex flex-wrap items-center gap-2">
        {archived ? (
          <Button size="sm" variant="affirmative" disabled={working} onClick={run}>
            {working ? "Restoring…" : "Bring it back"}
          </Button>
        ) : armed ? (
          <>
            <Button size="sm" variant="danger" disabled={working} onClick={run}>
              {working ? "Archiving…" : `Archive ${project.key}`}
            </Button>
            <Button size="sm" variant="ghost" disabled={working} onClick={() => setArmed(false)}>
              Cancel
            </Button>
          </>
        ) : (
          <Button size="sm" variant="destructive" disabled={working} onClick={() => setArmed(true)}>
            Archive
          </Button>
        )}

        {error !== null && (
          <p role="alert" className="text-caption text-s-danger">
            {error}
          </p>
        )}
      </div>
    </Panel>
  );
}
