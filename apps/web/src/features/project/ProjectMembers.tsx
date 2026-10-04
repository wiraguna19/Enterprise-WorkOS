"use client";

import { useState, useTransition } from "react";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import type { MessageKey } from "@/i18n/messages/en";
import { useT } from "@/i18n/I18nProvider";
import type { Translator } from "@/i18n/translate";
import {
  addProjectMember,
  removeProjectMember,
  setProjectMemberRole,
  type ProjectMember,
} from "./actions";

const ROLES = ["owner", "manager", "member", "viewer"];

/** A role in the reader's language; one the API adds later shows as it arrives (ADR 0060). */
function roleName(role: string, t: Translator): string {
  return ROLES.includes(role) ? t(`members.role.${role}` as MessageKey) : role;
}

/**
 * Who can see and work on a project (ADR 0041).
 *
 * A row grants access to a PERSON or a TEAM, never both, and the two are one
 * list here rather than two panels. A service account (ADR 0059) is a person
 * row as far as the API is concerned — a membership — and is offered and
 * labelled apart, because `/people` deliberately does not list it. Team access follows the team as people
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
  integrations = [],
  canManage,
}: {
  projectKey: string;
  members: ProjectMember[];
  people: Array<{ id: string; label: string }>;
  teams: Array<{ id: string; label: string }>;
  integrations?: Array<{ id: string; label: string }>;
  canManage: boolean;
}) {
  const t = useT();
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
        title={t("members.title")}
        description={t.plural("members.entries", members.length)}
        bleed
      >
        <DataTable caption={t("members.caption")}>
          <THead>
            <Tr>
              <Th>{t("projects.col.name")}</Th>
              <Th>{t("members.col.role")}</Th>
              <Th align="right">{t("members.col.actions")}</Th>
            </Tr>
          </THead>
          <TBody>
            {members.map((member) => (
              <Tr key={member.id}>
                <Td>
                  <span className="flex items-center gap-2">
                    {member.subject === "team" ? (
                      <Badge tone="info">{t("members.team")}</Badge>
                    ) : member.is_service ? (
                      <Badge tone="neutral">{t("members.integration")}</Badge>
                    ) : (
                      <Avatar id={member.membership_id ?? member.id} name={member.name ?? "?"} size="sm" />
                    )}
                    <span className="font-medium">{member.name ?? t("common.unnamed")}</span>
                  </span>
                </Td>
                <Td muted>
                  {canManage ? (
                    <select
                      aria-label={t("members.roleFor", { name: member.name ?? t("members.thisEntry") })}
                      value={member.role}
                      disabled={working}
                      onChange={(event) =>
                        run(() => setProjectMemberRole(projectKey, member.id, event.target.value))
                      }
                      className={INPUT.replace("w-full", "w-auto")}
                    >
                      {ROLES.map((role) => (
                        <option key={role} value={role}>
                          {roleName(role, t)}
                        </option>
                      ))}
                    </select>
                  ) : (
                    roleName(member.role, t)
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
                      {t("ms.remove")}
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
          integrations={integrations.filter((account) => !takenPeople.has(account.id))}
          working={working}
          onAdd={(input) => run(() => addProjectMember(projectKey, input))}
        />
      )}
    </div>
  );
}

type Subject = "person" | "team" | "integration";

// Words for each subject live in the dictionaries (ADR 0060):
// `give.label.*`, `give.subject.*`, `give.choose.*`, `give.exhausted.*`.

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
  integrations,
  working,
  onAdd,
}: {
  projectKey: string;
  people: Array<{ id: string; label: string }>;
  teams: Array<{ id: string; label: string }>;
  integrations: Array<{ id: string; label: string }>;
  working: boolean;
  onAdd: (input: { membership_id?: string; team_id?: string; role: string }) => void;
}) {
  const t = useT();
  const [subject, setSubject] = useState<Subject>("person");
  const [who, setWho] = useState("");
  const [role, setRole] = useState("member");

  const options = subject === "person" ? people : subject === "team" ? teams : integrations;

  return (
    <Panel
      id="add-member"
      title={t("give.title")}
      description={t("give.desc")}
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            size="sm"
            variant="primary"
            disabled={working || who === ""}
            onClick={() => {
              onAdd({
                // An integration is a membership, sent exactly as a person is.
                ...(subject === "team" ? { team_id: who } : { membership_id: who }),
                role,
              });
              setWho("");
            }}
          >
            {working ? t("give.adding") : t("give.add")}
          </Button>

          {who === "" && (
            <p role="status" className="text-caption text-n-500">
              {t(options.length === 0 ? `give.exhausted.${subject}` : `give.choose.${subject}`)}
            </p>
          )}
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <Field id={`${projectKey}-subject`} label={t("give.addLabel")}>
          <select
            id={`${projectKey}-subject`}
            value={subject}
            onChange={(event) => {
              setSubject(event.target.value as Subject);
              // Cleared on purpose: a membership id left in the box while the
              // switch says "team" is the one input this form must never send.
              setWho("");
            }}
            className={INPUT}
          >
            <option value="person">{t("give.subject.person")}</option>
            <option value="team">{t("give.subject.team")}</option>
            <option value="integration">{t("give.subject.integration")}</option>
          </select>
        </Field>

        <Field id={`${projectKey}-who`} label={t(`give.label.${subject}`)}>
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

        <Field id={`${projectKey}-role`} label={t("members.col.role")} hint={t("give.roleHint")}>
          <select
            id={`${projectKey}-role`}
            value={role}
            onChange={(event) => setRole(event.target.value)}
            className={INPUT}
          >
            {ROLES.map((candidate) => (
              <option key={candidate} value={candidate}>
                {roleName(candidate, t)}
              </option>
            ))}
          </select>
        </Field>
      </div>
    </Panel>
  );
}
