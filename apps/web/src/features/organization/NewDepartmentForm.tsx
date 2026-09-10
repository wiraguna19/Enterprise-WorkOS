"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { createDepartment } from "./actions";

/**
 * Creating a department (docs/02 §4).
 *
 * The code is first and the only field that is awkward to change later: it is
 * what a person types when they mean this part of the organization, and the
 * API scopes its uniqueness to the tenant. Its shape is stated where it is
 * typed rather than after the server has refused it — and lowercase is
 * accepted and upper-cased on the way out, because somebody typing "eng" means
 * ENG.
 *
 * The parent is optional and means "a root department" when left alone. That
 * is a real answer rather than a missing one, which is why the option says so
 * instead of reading "None".
 */
export function NewDepartmentForm({
  departments,
}: {
  departments: Array<{ id: string; label: string }>;
}) {
  const router = useRouter();

  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [parentId, setParentId] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | undefined>(undefined);
  const [submitting, startTransition] = useTransition();

  const codeId = useId();
  const nameId = useId();
  const parentFieldId = useId();

  return (
    <form
      className="max-w-2xl space-y-4"
      onSubmit={(event) => {
        event.preventDefault();

        startTransition(async () => {
          const result = await createDepartment({ name, code, parent_id: parentId });

          setError(result.error);
          setRequestId(result.requestId);

          if (result.error === null) router.push("/departments");
        });
      }}
    >
      <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
        <Field id={codeId} label="Code" hint="ENG, PEOPLE-OPS.">
          <input
            id={codeId}
            type="text"
            value={code}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
            // The API's own rule, stated once here so the field can refuse
            // before a round trip. The API remains the decider.
            pattern="[A-Za-z0-9-]+"
            maxLength={40}
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
            maxLength={120}
            required
            placeholder="Engineering"
            className={INPUT}
          />
        </Field>
      </div>

      <Field
        id={parentFieldId}
        label="Reports into"
        hint="Leave as a top-level department if it answers to nobody above it."
      >
        <select
          id={parentFieldId}
          value={parentId}
          onChange={(event) => setParentId(event.target.value)}
          className={INPUT}
        >
          <option value="">A top-level department</option>
          {departments.map((department) => (
            <option key={department.id} value={department.id}>
              {department.label}
            </option>
          ))}
        </select>
      </Field>

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
          {requestId && <span className="ml-2 font-mono text-n-500">{requestId}</span>}
        </p>
      )}

      <div className="flex items-center gap-2">
        <Button type="submit" variant="primary" disabled={submitting}>
          {submitting ? "Creating…" : "Create department"}
        </Button>
      </div>
    </form>
  );
}
