"use client";

import { useState, useTransition } from "react";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import {
  addProjectMember,
  removeProjectMember,
  setProjectMemberRole,
  type ProjectMember,
} from "./actions";

const ROLES = ["owner", "manager", "member", "viewer"];

/**
 * Who can see and work on a project (ADR 0041).
 *
 * A row grants access to a PERSON or a TEAM, never both, and the two are one
 * list here rather than two panels. Team access follows the team as people
 * join and leave it, which is the whole reason the column exists — a member
 * list assembled by hand from a team roster goes stale the first time somebody
 * moves.
 *
 * Removing is not deleting: the API keeps the row with a `removed_at`, because
 * who had access to a project and when is exactly the question an audit asks
 * later. The control says "Remove", which is what it does.
 */
export function ProjectMembers({
  projectKey,
  members,
  people,
  teams,
  canManage,
}: {
  projectKey: string;
  members: ProjectMember[];
  people: Array<{ id: string; label: string }>;
  teams: Array<{ id: string; label: string }>;
  canManage: boolean;
}) {
  const [error, setError] = useState<string | null>(null);
  const [working, start] = useTransition();

  function run(action: () => Promise<{ error: string | null }>): void {
    start(async () => setError((await action()).error));
  }

  // Already on the project, so not offered again. The API refuses a duplicate
  // with a sentence; not offering one is cheaper than explaining it.
  const takenPeople = new Set(members.map((member) => member.membership_id));
  const takenTeams = new Set(members.map((member) => member.team_id));

  return (
    <div className="space-y-4">
      {error !== null && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <Panel
        id="members"
        title="Access"
        description={`${members.length} ${members.length === 1 ? "entry" : "entries"}. A private project is visible to these people only.`}
        bleed
      >
        <DataTable caption="People and teams with access to this project">
          <THead>
            <Tr>
              <Th>Name</Th>
              <Th>Role</Th>
              <Th align="right">Actions</Th>
            </Tr>
          </THead>
          <TBody>
            {members.map((member) => (
              <Tr key={member.id}>
                <Td>
                  <span className="flex items-center gap-2">
                    {member.subject === "person" ? (
                      <Avatar id={member.membership_id ?? member.id} name={member.name ?? "?"} size="sm" />
                    ) : (
                      <Badge tone="info">team</Badge>
                    )}
                    <span className="font-medium">{member.name ?? "Unnamed"}</span>
                  </span>
                </Td>
                <Td muted>
                  {canManage ? (
                    <select
                      aria-label={`Role for ${member.name ?? "this entry"}`}
                      value={member.role}
                      disabled={working}
                      onChange={(event) =>
                        run(() => setProjectMemberRole(projectKey, member.id, event.target.value))
                      }
                      className={INPUT.replace("w-full", "w-auto")}
                    >
                      {ROLES.map((role) => (
                        <option key={role} value={role}>
                          {role}
                        </option>
                      ))}
                    </select>
                  ) : (
                    member.role
                  )}
                </Td>
                <Td align="right">
                  {canManage && (
                    <Button
                      size="sm"
                      variant="destructive"
                      disabled={working}
                      onClick={() => run(() => removeProjectMember(projectKey, member.id))}
                    >
                      Remove
                    </Button>
                  )}
                </Td>
              </Tr>
            ))}
          </TBody>
        </DataTable>
      </Panel>

      {canManage && (
        <AddMember
          projectKey={projectKey}
          people={people.filter((person) => !takenPeople.has(person.id))}
          teams={teams.filter((team) => !takenTeams.has(team.id))}
          working={working}
          onAdd={(input) => run(() => addProjectMember(projectKey, input))}
        />
      )}
    </div>
  );
}

/**
 * Adding one, with the person/team choice made explicitly.
 *
 * A single "who" picker mixing people and teams would have to encode which
 * kind each option is, and the first thing to read that encoding wrongly sends
 * a team id as a membership id. Two pickers and one switch say it in the
 * markup instead.
 */
function AddMember({
  projectKey,
  people,
  teams,
  working,
  onAdd,
}: {
  projectKey: string;
  people: Array<{ id: string; label: string }>;
  teams: Array<{ id: string; label: string }>;
  working: boolean;
  onAdd: (input: { membership_id?: string; team_id?: string; role: string }) => void;
}) {
  const [subject, setSubject] = useState<"person" | "team">("person");
  const [who, setWho] = useState("");
  const [role, setRole] = useState("member");

  const options = subject === "person" ? people : teams;

  return (
    <Panel
      id="add-member"
      title="Give access"
      description="A team's access follows the team — people who join it get access, people who leave lose it."
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            size="sm"
            variant="primary"
            disabled={working || who === ""}
            onClick={() => {
              onAdd({
                ...(subject === "person" ? { membership_id: who } : { team_id: who }),
                role,
              });
              setWho("");
            }}
          >
            {working ? "Adding…" : "Add"}
          </Button>

          {who === "" && (
            <p role="status" className="text-caption text-n-500">
              {options.length === 0
                ? `Everyone ${subject === "person" ? "" : "and every team "}already has access.`
                : `Choose a ${subject}.`}
            </p>
          )}
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <Field id={`${projectKey}-subject`} label="Add">
          <select
            id={`${projectKey}-subject`}
            value={subject}
            onChange={(event) => {
              setSubject(event.target.value as "person" | "team");
              // Cleared on purpose: a membership id left in the box while the
              // switch says "team" is the one input this form must never send.
              setWho("");
            }}
            className={INPUT}
          >
            <option value="person">a person</option>
            <option value="team">a team</option>
          </select>
        </Field>

        <Field id={`${projectKey}-who`} label={subject === "person" ? "Person" : "Team"}>
          <select
            id={`${projectKey}-who`}
            value={who}
            onChange={(event) => setWho(event.target.value)}
            className={INPUT}
          >
            <option value="">—</option>
            {options.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </Field>

        <Field id={`${projectKey}-role`} label="Role" hint="Owners and managers can change the project.">
          <select
            id={`${projectKey}-role`}
            value={role}
            onChange={(event) => setRole(event.target.value)}
            className={INPUT}
          >
            {ROLES.map((candidate) => (
              <option key={candidate} value={candidate}>
                {candidate}
              </option>
            ))}
          </select>
        </Field>
      </div>
    </Panel>
  );
}
