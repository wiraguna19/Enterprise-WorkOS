"use client";

import { useState, useTransition } from "react";
import { DataTable, TBody, THead, Th, Tr } from "@/components/ui/DataTable";
import { saveNotificationPreference } from "./actions";
import type { NotificationType, Preference } from "./types";

/**
 * One group of notification types, in two layouts (docs/08 §6, docs/09 §5).
 *
 * A four-column table at 375px is the thing docs/08 §6 names outright: an
 * unusable table rendered smaller. On a phone each type becomes a block with
 * its three controls labelled underneath — more vertical space, but every
 * control is reachable and every one says what it does.
 *
 * Two rules are visible in the UI because the database enforces them and a form
 * that let you break them would just produce a 422: immediate email and a
 * digest are mutually exclusive, and a few types cannot be muted in app at all.
 *
 * **This used to save nothing.** Every control was `defaultChecked` with no
 * handler — a whole screen of dead controls, and the most convincing kind,
 * because it renders your saved state back at you. It became a client
 * component when the toggles were wired; the layouts below are unchanged.
 *
 * **And then it took a FUNCTION as a prop** — `preferenceFor(key)`, resolved on
 * the server — which a Server Component cannot hand to a Client Component:
 * props cross that boundary by serialization, and a closure does not
 * serialize. The whole screen threw at render. It is data now, resolved before
 * it crosses: the server does the lookup, and what arrives is a list.
 */
export function PreferenceGroup({
  entries,
}: {
  /** Each type with the preference already resolved for it. */
  entries: Array<{ type: NotificationType; saved: Preference }>;
}) {
  return (
    <>
      {/* ── Phone: one block per type ───────────────────────────────────── */}
      <ul className="divide-y divide-n-100 px-4 md:hidden">
        {entries.map((entry) => (
          <PreferenceRow
            key={entry.type.key}
            type={entry.type}
            saved={entry.saved}
            layout="block"
          />
        ))}
      </ul>

      {/* ── Desktop: comparison table ─────────────────────────────────────
          On the shared primitives (ADR 0024), so the padding and the row height
          are the product's rather than this file's fourth private opinion. */}
      <div className="hidden md:block">
        <DataTable caption="Notification preferences">
          <THead>
            <Tr>
              <Th>Event</Th>
              <Th width="w-24" align="right">
                In app
              </Th>
              <Th width="w-24" align="right">
                Email
              </Th>
              <Th width="w-32" align="right">
                Digest
              </Th>
            </Tr>
          </THead>

          <TBody>
            {entries.map((entry) => (
              <PreferenceRow
                key={entry.type.key}
                type={entry.type}
                saved={entry.saved}
                layout="row"
              />
            ))}
          </TBody>
        </DataTable>
      </div>
    </>
  );
}

/**
 * One type's three controls, in either layout.
 *
 * The state is held here and corrected from the server: a checkbox that does
 * not move when clicked feels broken, so it moves — and if the save is refused,
 * it moves back and says why. That is not optimism about the outcome (ADR
 * 0012); it is a control reflecting the input it was given until the server
 * disagrees.
 */
function PreferenceRow({
  type,
  saved,
  layout,
}: {
  type: NotificationType;
  saved: Preference;
  layout: "block" | "row";
}) {
  const [preference, setPreference] = useState(saved);
  const [error, setError] = useState<string | null>(null);
  const [saving, startTransition] = useTransition();

  const save = (change: Partial<Preference>) => {
    const next = { ...preference, ...change };

    // Email and a digest are mutually exclusive in the database. Turning a
    // digest on therefore turns email off HERE too, rather than sending a pair
    // the API will refuse — the constraint is the API's, but a form that walks
    // the user into a 422 it could see coming is a form that wastes their time.
    if (next.digest !== "off") next.email = false;

    const previous = preference;

    setPreference(next);
    setError(null);

    startTransition(async () => {
      const result = await saveNotificationPreference(next);

      if (result.error !== null) {
        // Put it back. A control that keeps the value the server rejected is
        // lying about what will happen tomorrow morning.
        setPreference(previous);
        setError(result.error);
      }
    });
  };

  const inApp = type.alwaysInApp || preference.in_app;

  if (layout === "block") {
    return (
      <li className="py-3">
        <div className="text-body-sm text-n-900">
          {type.label}
          {type.alwaysInApp && (
            <span className="ml-1.5 text-caption text-n-500">· always in app</span>
          )}
        </div>

        <div className="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2">
          <Toggle
            label="In app"
            ariaLabel={`${type.label} in app`}
            checked={inApp}
            disabled={type.alwaysInApp || saving}
            onChange={(value) => save({ in_app: value })}
          />

          <Toggle
            label="Email"
            ariaLabel={`${type.label} by email`}
            checked={preference.email}
            disabled={preference.digest !== "off" || saving}
            onChange={(value) => save({ email: value })}
          />

          <label className="flex items-center gap-1.5 text-caption text-n-500">
            Digest
            <DigestSelect
              type={type}
              preference={preference}
              disabled={saving}
              onChange={(digest) => save({ digest })}
            />
          </label>
        </div>

        {error !== null && (
          <p role="alert" className="mt-1 text-caption text-s-danger">
            {error}
          </p>
        )}
      </li>
    );
  }

  return (
    <tr className="h-[var(--row-height)] hover:bg-n-25">
      <th
        scope="row"
        className="px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] text-left text-body-sm font-normal text-n-900"
      >
        {type.label}
        {type.alwaysInApp && (
          <span className="ml-1.5 text-caption text-n-500">· always in app</span>
        )}
        {error !== null && (
          <span role="alert" className="ml-1.5 text-caption text-s-danger">
            {error}
          </span>
        )}
      </th>

      <td className="px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] text-right">
        <input
          type="checkbox"
          aria-label={`${type.label} in app`}
          checked={inApp}
          disabled={type.alwaysInApp || saving}
          onChange={(event) => save({ in_app: event.target.checked })}
        />
      </td>

      <td className="px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] text-right">
        <input
          type="checkbox"
          aria-label={`${type.label} by email`}
          checked={preference.email}
          // Not a hidden rule: the checkbox is visibly unavailable while a
          // digest is on, which explains the constraint better than an error
          // after saving would.
          disabled={preference.digest !== "off" || saving}
          onChange={(event) => save({ email: event.target.checked })}
        />
      </td>

      <td className="px-[var(--cell-padding-x)] py-[var(--cell-padding-y)] text-right">
        <DigestSelect
          type={type}
          preference={preference}
          disabled={saving}
          onChange={(digest) => save({ digest })}
        />
      </td>
    </tr>
  );
}

function Toggle({
  label,
  ariaLabel,
  checked,
  disabled,
  onChange,
}: {
  label: string;
  ariaLabel: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <label className="flex items-center gap-1.5 text-caption text-n-500">
      <input
        type="checkbox"
        aria-label={ariaLabel}
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        className="size-4"
      />
      {label}
    </label>
  );
}

function DigestSelect({
  type,
  preference,
  disabled,
  onChange,
}: {
  type: NotificationType;
  preference: Preference;
  disabled?: boolean;
  onChange: (digest: Preference["digest"]) => void;
}) {
  return (
    <select
      aria-label={`${type.label} digest`}
      value={preference.digest}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value as Preference["digest"])}
      className="rounded-sm border border-n-200 bg-n-0 px-1.5 py-1 text-body-sm text-n-900"
    >
      <option value="off">Off</option>
      <option value="daily">Daily</option>
      <option value="weekly">Weekly</option>
    </select>
  );
}
