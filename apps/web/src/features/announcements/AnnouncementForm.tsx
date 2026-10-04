"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import { publishAnnouncement, updateAnnouncement } from "./actions";
import { audienceName } from "./audience";
import type { Announcement, Audience } from "./types";

const BODY_LIMIT = 5000;

/**
 * Writing an announcement, or correcting one (ADR 0061).
 *
 * The audience is chosen from what the API served — the groups this person may
 * address — and only when writing. It cannot be changed afterwards, so the
 * edit form shows it as text instead of offering a control that would be
 * refused.
 */
export function AnnouncementForm({
  audiences,
  existing,
}: {
  /** For a new announcement: every group this person may address. */
  audiences?: Audience[];
  /** For a correction: the announcement as it stands. */
  existing?: Announcement;
}) {
  const t = useT();
  const router = useRouter();
  const id = useId();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const options = audiences ?? [];
  const [audience, setAudience] = useState(options.length > 0 ? keyOf(options[0]) : "");
  const [title, setTitle] = useState(existing?.title ?? "");
  const [body, setBody] = useState(existing?.body ?? "");
  const [pinned, setPinned] = useState(existing?.pinned ?? false);
  const [acknowledge, setAcknowledge] = useState(existing?.requires_acknowledgement ?? false);
  const [expiresOn, setExpiresOn] = useState(existing?.expires_at ? existing.expires_at.slice(0, 10) : "");

  function submit(event: React.FormEvent) {
    event.preventDefault();

    const input = {
      title: title.trim(),
      body: body.trim(),
      pinned,
      requires_acknowledgement: acknowledge,
      // The end of the chosen day, in the reader's own time: "until Friday"
      // means it is still up on Friday.
      expires_at: expiresOn === "" ? null : new Date(`${expiresOn}T23:59:59`).toISOString(),
    };

    startTransition(async () => {
      let result;

      if (existing) {
        result = await updateAnnouncement(existing.id, input);
      } else {
        const chosen = options.find((option) => keyOf(option) === audience);

        if (!chosen) return;

        result = await publishAnnouncement({ ...input, audience_type: chosen.type, audience_id: chosen.id });
      }

      setError(result.error);

      if (result.error === null && result.id) {
        router.push(`/announcements/${result.id}`);
        router.refresh();
      }
    });
  }

  const groups: Array<{ label: string; items: Audience[] }> = [
    { label: t("ann.form.everyone"), items: options.filter((option) => option.type === "organization") },
    { label: t("ann.form.departments"), items: options.filter((option) => option.type === "department") },
    { label: t("ann.form.teams"), items: options.filter((option) => option.type === "team") },
  ].filter((group) => group.items.length > 0);

  return (
    <form onSubmit={submit} className="max-w-2xl space-y-4">
      {error && (
        <p role="alert" className="rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {existing ? (
        <p className="text-body-sm text-n-700">
          {t("ann.form.sentTo", { audience: audienceName(existing.audience, t) })}
        </p>
      ) : (
        <Field id={`${id}-audience`} label={t("ann.form.audience")} hint={t("ann.form.audienceHint")}>
          <select
            id={`${id}-audience`}
            className={INPUT}
            value={audience}
            onChange={(event) => setAudience(event.target.value)}
          >
            {groups.map((group) => (
              <optgroup key={group.label} label={group.label}>
                {group.items.map((option) => (
                  <option key={keyOf(option)} value={keyOf(option)}>
                    {audienceName(option, t)}
                  </option>
                ))}
              </optgroup>
            ))}
          </select>
        </Field>
      )}

      <Field id={`${id}-title`} label={t("ann.form.title")}>
        <input
          id={`${id}-title`}
          className={INPUT}
          value={title}
          maxLength={160}
          required
          onChange={(event) => setTitle(event.target.value)}
        />
      </Field>

      <Field
        id={`${id}-body`}
        label={t("ann.form.body")}
        hint={t("ann.form.bodyHint", { used: body.length, limit: BODY_LIMIT })}
      >
        <textarea
          id={`${id}-body`}
          className={`${INPUT} min-h-40`}
          value={body}
          maxLength={BODY_LIMIT}
          required
          rows={8}
          onChange={(event) => setBody(event.target.value)}
        />
      </Field>

      <Field id={`${id}-expires`} label={t("ann.form.expires")} hint={t("ann.form.expiresHint")} className="max-w-xs">
        <input
          id={`${id}-expires`}
          type="date"
          className={INPUT}
          value={expiresOn}
          onChange={(event) => setExpiresOn(event.target.value)}
        />
      </Field>

      <fieldset className="space-y-2">
        <legend className="sr-only">{t("ann.form.options")}</legend>
        <label className="flex items-start gap-2 text-body-sm text-n-900">
          <input type="checkbox" className="mt-1" checked={pinned} onChange={(event) => setPinned(event.target.checked)} />
          <span>
            {t("ann.form.pin")}
            <span className="block text-caption text-n-500">{t("ann.form.pinHint")}</span>
          </span>
        </label>
        <label className="flex items-start gap-2 text-body-sm text-n-900">
          <input
            type="checkbox"
            className="mt-1"
            checked={acknowledge}
            onChange={(event) => setAcknowledge(event.target.checked)}
          />
          <span>
            {t("ann.form.acknowledge")}
            <span className="block text-caption text-n-500">{t("ann.form.acknowledgeHint")}</span>
          </span>
        </label>
      </fieldset>

      <Button type="submit" variant="primary" disabled={pending || title.trim() === "" || body.trim() === ""}>
        {existing
          ? pending ? t("ann.form.saving") : t("ann.form.save")
          : pending ? t("ann.form.publishing") : t("ann.form.publish")}
      </Button>
    </form>
  );
}

function keyOf(audience: Audience): string {
  return `${audience.type}:${audience.id ?? ""}`;
}
