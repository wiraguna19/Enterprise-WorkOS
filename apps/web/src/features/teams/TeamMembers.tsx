"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { Avatar } from "@/components/ui/Avatar";
import { Button } from "@/components/ui/Button";
import { addTeamMember, removeTeamMember } from "./actions";
import type { TeamMember } from "./types";

/**
 * Who is on the team, and changing it (docs/08 §2).
 *
 * Removing someone is not destructive and does not ask for confirmation: the
 * membership row is closed, not deleted, and the person is one click from being
 * added back. A confirmation dialog for a reversible action trains people to
 * dismiss the ones that matter.
 */
export function TeamMembers({
  teamId,
  members,
  candidates,
  canManage,
}: {
  teamId: string;
  members: TeamMember[];
  candidates: Array<{ id: string; name: string }>;
  canManage: boolean;
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startTransition] = useTransition();
  const [adding, setAdding] = useState("");

  const run = (work: () => Promise<{ error: string | null }>) =>
    startTransition(async () => {
      setError((await work()).error);
    });

  return (
    <div className="space-y-3">
      <ul className="divide-y divide-n-100 border-y border-n-100">
        {members.map((member) => (
          <li key={member.membership_id} className="flex items-center gap-3 py-2">
            <Avatar id={member.membership_id} name={member.name ?? "?"} />

            <Link
              href={`/people/${member.membership_id}`}
              className="min-w-0 flex-1 hover:text-a-700"
            >
              <span className="block truncate text-body-sm font-medium text-n-900">
                {member.name ?? "Unknown"}
              </span>
              <span className="block truncate text-caption text-n-500">
                {member.job_title ?? "—"}
              </span>
            </Link>

            {member.role === "lead" && (
              <span className="shrink-0 rounded-full border border-n-200 px-2 py-0.5 text-micro uppercase tracking-[0.04em] text-n-500">
                Lead
              </span>
            )}

            {canManage && (
              <Button
                size="sm"
                variant="ghost"
                disabled={busy}
                onClick={() => run(() => removeTeamMember(teamId, member.membership_id))}
              >
                Remove
              </Button>
            )}
          </li>
        ))}
      </ul>

      {canManage && candidates.length > 0 && (
        <form
          className="flex flex-wrap items-center gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            if (adding === "") return;
            run(async () => {
              const result = await addTeamMember(teamId, adding);
              if (result.error === null) setAdding("");

              return result;
            });
          }}
        >
          <label htmlFor="add-member" className="sr-only">
            Add someone to this team
          </label>
          <select
            id="add-member"
            value={adding}
            onChange={(event) => setAdding(event.target.value)}
            className="rounded-md border border-n-200 bg-n-0 px-2 py-1 text-body-sm text-n-900"
          >
            <option value="">Add someone…</option>
            {candidates.map((person) => (
              <option key={person.id} value={person.id}>
                {person.name}
              </option>
            ))}
          </select>

          <Button type="submit" size="sm" disabled={busy || adding === ""}>
            {busy ? "Working…" : "Add"}
          </Button>
        </form>
      )}

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
        </p>
      )}
    </div>
  );
}
