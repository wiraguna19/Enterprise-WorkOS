"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { acceptInvitation } from "@/features/people/invitations";

/**
 * Accepting an invitation.
 *
 * On success it sends them to the sign-in page rather than signing them in:
 * accepting creates the account, and logging in is the account's own act with
 * its own audit trail and its own rate limit. It also means the first thing
 * they do is prove the password they just chose actually works.
 */
export function AcceptInviteForm({ token }: { token: string }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();

        startAction(async () => {
          const result = await acceptInvitation(token, { name, password });

          setError(result.error);

          if (result.error === null) router.push("/login");
        });
      }}
    >
      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <Field id="name" label="Your name">
        <input
          id="name"
          className={INPUT}
          value={name}
          required
          maxLength={120}
          autoComplete="name"
          onChange={(event) => setName(event.target.value)}
        />
      </Field>

      <Field id="password" label="Password" hint="At least twelve characters.">
        <input
          id="password"
          type="password"
          className={INPUT}
          value={password}
          required
          minLength={12}
          autoComplete="new-password"
          onChange={(event) => setPassword(event.target.value)}
        />
      </Field>

      <Button type="submit" variant="primary" disabled={busy || name === "" || password === ""}>
        {busy ? "Joining…" : "Join"}
      </Button>
    </form>
  );
}
