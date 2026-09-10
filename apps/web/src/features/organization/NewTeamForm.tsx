"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { createTeam } from "./actions";

type Option = { id: string; label: string };

/**
 * Creating a team (docs/02 §4, docs/08 §2).
 *
 * A team could gain and lose members since Phase 2 and could not be created:
 * every team in this product came from the seed. The members are NOT collected
 * here — the team page already adds and removes them, and asking twice would
 * be a second implementation of the same decision. The lead is, because a team
 * with nobody accountable for it is the state this form exists to avoid.
 */
export function NewTeamForm({
  departments,
  people,
}: {
  departments: Option[];
  people: Option[];
}) {
  const router = useRouter();

  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [departmentId, setDepartmentId] = useState("");
  const [leadId, setLeadId] = useState("");

  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | undefined>(undefined);
  const [submitting, startTransition] = useTransition();

  const keyId = useId();
  const nameId = useId();
  const departmentFieldId = useId();
  const leadFieldId = useId();

  return (
    <form
      className="max-w-2xl space-y-4"
      onSubmit={(event) => {
        event.preventDefault();

        startTransition(async () => {
          const result = await createTeam({
            name,
            key,
            department_id: departmentId,
            lead_membership_id: leadId,
          });

          setError(result.error);
          setRequestId(result.requestId);

          if (result.error === null && result.id !== undefined) {
            router.push(`/teams/${result.id}`);
          }
        });
      }}
    >
      <div className="grid gap-4 sm:grid-cols-[8rem_1fr]">
        <Field id={keyId} label="Key" hint="Short, and how people will refer to it.">
          <input
            id={keyId}
            type="text"
            value={key}
            onChange={(event) => setKey(event.target.value.toUpperCase())}
            pattern="[A-Za-z0-9-]+"
            maxLength={40}
            required
            autoFocus
            placeholder="PLATFORM"
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
            placeholder="Platform"
            className={INPUT}
          />
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field id={departmentFieldId} label="Department" hint="Optional.">
          <select
            id={departmentFieldId}
            value={departmentId}
            onChange={(event) => setDepartmentId(event.target.value)}
            className={INPUT}
          >
            <option value="">No department</option>
            {departments.map((department) => (
              <option key={department.id} value={department.id}>
                {department.label}
              </option>
            ))}
          </select>
        </Field>

        <Field id={leadFieldId} label="Lead" hint="Who answers for this team.">
          <select
            id={leadFieldId}
            value={leadId}
            onChange={(event) => setLeadId(event.target.value)}
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

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
          {requestId && <span className="ml-2 font-mono text-n-500">{requestId}</span>}
        </p>
      )}

      <Button type="submit" variant="primary" disabled={submitting}>
        {submitting ? "Creating…" : "Create team"}
      </Button>
    </form>
  );
}
