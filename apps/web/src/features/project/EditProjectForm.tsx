"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import type { MessageKey } from "@/i18n/messages/en";
import { priorityName } from "@/i18n/labels";
import { useT } from "@/i18n/I18nProvider";
import type { Project } from "@/features/work-item/types";
import { setProjectArchived, updateProject, type ProjectEdit } from "./actions";

const STATUSES = ["planning", "active", "on_hold", "completed", "cancelled"];

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
export function EditProjectForm({
  project,
  priorities,
}: {
  project: Project;
  /** Served by `GET /work-items/vocabulary` (ADR 0047) — one scale for projects and work. */
  priorities: string[];
}) {
  const router = useRouter();

  const t = useT();
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
        title={t("pedit.details")}
        description={
          <>
            {t("pedit.keyNote.before")} <code className="font-mono">{project.key}</code>{" "}
            {t("pedit.keyNote.after")}
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
              {saving ? t("common.saving") : t("pedit.saveChanges")}
            </Button>

            {name.trim().length < 2 && (
              <p role="status" className="text-caption text-n-500">
                {t("pedit.nameTooShort")}
              </p>
            )}
          </div>
        }
      >
        <div className="space-y-3">
          <Field id={nameId} label={t("projects.col.name")}>
            <input
              id={nameId}
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={160}
              className={INPUT}
            />
          </Field>

          <Field id={descriptionId} label={t("wi.description")}>
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
              label={t("status.status")}
              hint={t("pedit.statusHint")}
            >
              <select
                id={statusId}
                value={status}
                onChange={(event) => setStatus(event.target.value)}
                className={INPUT}
              >
                {STATUSES.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {t(`project.status.${candidate}` as MessageKey)}
                  </option>
                ))}
              </select>
            </Field>

            <Field id={priorityId} label={t("form.priority")}>
              <select
                id={priorityId}
                value={priority}
                onChange={(event) => setPriority(event.target.value as Project["priority"])}
                className={INPUT}
              >
                {(priorities.includes(project.priority) ? priorities : [project.priority, ...priorities]).map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {priorityName(candidate, t)}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              id={visibilityId}
              label={t("pedit.visibility")}
              hint={t("pedit.visHint")}
            >
              <select
                id={visibilityId}
                value={visibility}
                onChange={(event) => setVisibility(event.target.value as Project["visibility"])}
                className={INPUT}
              >
                <option value="internal">{t("pedit.internal")}</option>
                <option value="private">{t("projects.private")}</option>
              </select>
            </Field>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <Field id={startId} label={t("pedit.starts")} hint={t("pedit.startsHint")}>
              <input
                id={startId}
                type="date"
                value={startDate}
                onChange={(event) => setStartDate(event.target.value)}
                className={INPUT}
              />
            </Field>

            <Field id={endId} label={t("pedit.ends")} hint={t("pedit.endsHint")}>
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
                {t("pedit.conflict", { current: conflict.current, yours: conflict.yours })}
              </p>
              <Button size="sm" onClick={() => router.refresh()}>
                {t("pedit.reload")}
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
  const t = useT();
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
      title={archived ? t("parchive.archived") : t("parchive.title")}
      description={archived ? t("parchive.archivedDesc") : t("parchive.desc")}
      tone={archived ? "default" : "danger"}
    >
      <div className="flex flex-wrap items-center gap-2">
        {archived ? (
          <Button size="sm" variant="affirmative" disabled={working} onClick={run}>
            {working ? t("archived.restoring") : t("parchive.bringBack")}
          </Button>
        ) : armed ? (
          <>
            <Button size="sm" variant="danger" disabled={working} onClick={run}>
              {working ? t("parchive.archiving") : t("parchive.archiveKey", { key: project.key })}
            </Button>
            <Button size="sm" variant="ghost" disabled={working} onClick={() => setArmed(false)}>
              {t("common.cancel")}
            </Button>
          </>
        ) : (
          <Button size="sm" variant="destructive" disabled={working} onClick={() => setArmed(true)}>
            {t("parchive.archive")}
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
