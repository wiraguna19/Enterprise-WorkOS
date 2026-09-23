"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
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
export function NewProjectForm({ departments }: { departments: Option[] }) {
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
        title="Project"
        description="The key is permanent; everything else can change later."
        footer={
          <div className="space-y-2">
            <div className="flex items-center gap-3">
              <Button
                type="submit"
                variant="primary"
                disabled={submitting || key.trim() === "" || name.trim().length < 2}
              >
                {submitting ? "Creating…" : "Create project"}
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
        <Field id={keyId} label="Key" hint="ENG, OPS-style. Permanent.">
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

        <Field id={nameId} label="Name">
          <input
            id={nameId}
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            minLength={2}
            maxLength={160}
            required
            placeholder="What this project is for"
            className={INPUT}
          />
        </Field>
      </div>

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
          id={visibilityId}
          label="Visibility"
          hint="Private means members only, and it is not reversible from here."
        >
          <select
            id={visibilityId}
            value={visibility}
            onChange={(event) => setVisibility(event.target.value)}
            className={INPUT}
          >
            <option value="">Default</option>
            <option value="internal">Internal — anyone in the organization</option>
            <option value="private">Private — project members only</option>
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
            {["low", "medium", "high", "urgent"].map((value) => (
              <option key={value} value={value}>
                {value.charAt(0).toUpperCase() + value.slice(1)}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id={departmentFieldId}
          label="Department"
          hint="Optional. Reports split delivered work by this."
        >
          <select
            id={departmentFieldId}
            value={departmentId}
            onChange={(event) => setDepartmentId(event.target.value)}
            className={INPUT}
          >
            <option value="">None</option>
            {departments.map((department) => (
              <option key={department.id} value={department.id}>
                {department.label}
              </option>
            ))}
          </select>
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field id={startId} label="Start">
            <input
              id={startId}
              type="date"
              value={startDate}
              onChange={(event) => setStartDate(event.target.value)}
              className={INPUT}
            />
          </Field>

          <Field id={endId} label="End">
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
