"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { priorityName } from "@/i18n/labels";
import { useT } from "@/i18n/I18nProvider";
import { createProject } from "./actions";

type Option = { id: string; label: string };

/**
 * Creating a project (docs/08 §2).
 *
 * The key is the first field and the only one that cannot be changed by
 * shrugging: it becomes the URL of every page this project has, and the prefix
 * of every reference printed on every item in it. So its shape is stated where
 * it is typed — two to twelve characters, letters and digits, starting with a
 * letter — rather than after the server has refused it. Lowercase is accepted
 * and upper-cased on the way out, because somebody typing "eng" means ENG and
 * bouncing them for it teaches nothing.
 *
 * What is NOT here: the workflow. The API picks the organization's default for
 * tasks, and offering a choice would imply this product has a workflow builder.
 * It does not yet — Phase 7 owns that — and an empty picker that promises one
 * is how a screen starts lying.
 *
 * The creator becomes the owner and a member, in the same transaction, on the
 * server. Nothing here says so, because nothing here decides it.
 */
export function NewProjectForm({
  departments,
  priorities,
}: {
  departments: Option[];
  /**
   * Served by `GET /work-items/vocabulary` (ADR 0047). Projects and work items
   * share one priority scale — the two tables carry the same CHECK — so there
   * is one list to read, not a copy per form.
   */
  priorities: string[];
}) {
  const t = useT();
  const router = useRouter();

  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [departmentId, setDepartmentId] = useState("");
  const [visibility, setVisibility] = useState("");
  const [priority, setPriority] = useState("");
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | undefined>(undefined);
  const [submitting, startTransition] = useTransition();

  const keyId = useId();
  const nameId = useId();
  const descriptionId = useId();
  const departmentFieldId = useId();
  const visibilityId = useId();
  const priorityId = useId();
  const startId = useId();
  const endId = useId();

  const submit = () =>
    startTransition(async () => {
      const result = await createProject({
        key,
        name,
        description,
        department_id: departmentId,
        visibility,
        priority,
        start_date: startDate,
        end_date: endDate,
      });

      setError(result.error);
      setRequestId(result.requestId);

      if (result.error === null && result.key !== undefined) {
        router.push(`/projects/${result.key}/overview`);
      }
    });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      {/* A form is a section like any other, and until now it was the one kind
          of content with no container at all: fields floated in the column and
          the submit row was a rule somebody drew by hand. The actions live in
          the panel's footer, on their own surface, where every other
          consequential control in the product sits (ADR 0024). */}
      <Panel
        id="new-project"
        title={t("pnew.panel")}
        description={t("pnew.panelDesc")}
        footer={
          <div className="space-y-2">
            <div className="flex items-center gap-3">
              <Button
                type="submit"
                variant="primary"
                disabled={submitting || key.trim() === "" || name.trim().length < 2}
              >
                {submitting ? t("pnew.creating") : t("pnew.create")}
              </Button>

              <Button
                type="button"
                variant="ghost"
                disabled={submitting}
                onClick={() => router.back()}
              >
                {t("common.cancel")}
              </Button>
            </div>

            {error && (
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
      <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
        <Field id={keyId} label={t("projects.col.key")} hint={t("pnew.keyHint")}>
          <input
            id={keyId}
            type="text"
            value={key}
            onChange={(event) => setKey(event.target.value.toUpperCase())}
            // The same shape the API enforces, stated once here so the field
            // can refuse before a round trip. The API remains the decider.
            pattern="[A-Za-z][A-Za-z0-9]{1,11}"
            maxLength={12}
            required
            autoFocus
            placeholder="ENG"
            className={`${INPUT} font-mono uppercase`}
          />
        </Field>

        <Field id={nameId} label={t("projects.col.name")}>
          <input
            id={nameId}
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            minLength={2}
            maxLength={160}
            required
            placeholder={t("pnew.namePlaceholder")}
            className={INPUT}
          />
        </Field>
      </div>

      <Field id={descriptionId} label={t("wi.description")} hint={t("pnew.descHint")}>
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
          id={visibilityId}
          label={t("pedit.visibility")}
          hint={t("pnew.visHint")}
        >
          <select
            id={visibilityId}
            value={visibility}
            onChange={(event) => setVisibility(event.target.value)}
            className={INPUT}
          >
            <option value="">{t("form.default")}</option>
            <option value="internal">{t("pnew.vis.internal")}</option>
            <option value="private">{t("pnew.vis.private")}</option>
          </select>
        </Field>

        <Field id={priorityId} label={t("form.priority")}>
          <select
            id={priorityId}
            value={priority}
            onChange={(event) => setPriority(event.target.value)}
            className={INPUT}
          >
            <option value="">{t("form.default")}</option>
            {priorities.map((value) => (
              <option key={value} value={value}>
                {priorityName(value, t)}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id={departmentFieldId}
          label={t("pnew.department")}
          hint={t("pnew.deptHint")}
        >
          <select
            id={departmentFieldId}
            value={departmentId}
            onChange={(event) => setDepartmentId(event.target.value)}
            className={INPUT}
          >
            <option value="">{t("form.none")}</option>
            {departments.map((department) => (
              <option key={department.id} value={department.id}>
                {department.label}
              </option>
            ))}
          </select>
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field id={startId} label={t("pnew.start")}>
            <input
              id={startId}
              type="date"
              value={startDate}
              onChange={(event) => setStartDate(event.target.value)}
              className={INPUT}
            />
          </Field>

          <Field id={endId} label={t("pnew.end")}>
            <input
              id={endId}
              type="date"
              value={endDate}
              // The API refuses an end before its start; so does the picker.
              min={startDate === "" ? undefined : startDate}
              onChange={(event) => setEndDate(event.target.value)}
              className={INPUT}
            />
          </Field>
        </div>
      </div>

      </div>
      </Panel>
    </form>
  );
}
