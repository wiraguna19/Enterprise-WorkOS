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
 */
export function PreferenceGroup({
  types,
  preferenceFor,
}: {
  types: NotificationType[];
  preferenceFor: (key: string) => Preference;
}) {
  return (
    <>
      {/* ── Phone: one block per type ───────────────────────────────────── */}
      <ul className="mt-3 divide-y divide-n-100 border-y border-n-100 md:hidden">
        {types.map((type) => {
          const preference = preferenceFor(type.key);

          return (
            <li key={type.key} className="py-3">
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
                  checked={type.alwaysInApp || preference.in_app}
                  disabled={type.alwaysInApp}
                />

                <Toggle
                  label="Email"
                  ariaLabel={`${type.label} by email`}
                  checked={preference.email}
                  disabled={preference.digest !== "off"}
                />

                <label className="flex items-center gap-1.5 text-caption text-n-500">
                  Digest
                  <DigestSelect type={type} preference={preference} />
                </label>
              </div>
            </li>
          );
        })}
      </ul>

      {/* ── Desktop: comparison table ───────────────────────────────────── */}
      <table className="mt-3 hidden w-full text-body-sm md:table">
        <thead>
          <tr className="border-b border-n-100 text-left text-caption text-n-500">
            <th scope="col" className="py-1.5 font-normal">
              Event
            </th>
            <th scope="col" className="w-20 py-1.5 text-center font-normal">
              In app
            </th>
            <th scope="col" className="w-20 py-1.5 text-center font-normal">
              Email
            </th>
            <th scope="col" className="w-28 py-1.5 text-center font-normal">
              Digest
            </th>
          </tr>
        </thead>

        <tbody className="divide-y divide-n-100">
          {types.map((type) => {
            const preference = preferenceFor(type.key);

            return (
              <tr key={type.key}>
                <th scope="row" className="py-2 text-left font-normal text-n-900">
                  {type.label}
                  {type.alwaysInApp && (
                    <span className="ml-1.5 text-caption text-n-500">· always in app</span>
                  )}
                </th>

                <td className="py-2 text-center">
                  <input
                    type="checkbox"
                    aria-label={`${type.label} in app`}
                    defaultChecked={type.alwaysInApp || preference.in_app}
                    disabled={type.alwaysInApp}
                  />
                </td>

                <td className="py-2 text-center">
                  <input
                    type="checkbox"
                    aria-label={`${type.label} by email`}
                    defaultChecked={preference.email}
                    // Not a hidden rule: the checkbox is visibly unavailable
                    // while a digest is on, which explains the constraint
                    // better than an error after saving would.
                    disabled={preference.digest !== "off"}
                  />
                </td>

                <td className="py-2 text-center">
                  <DigestSelect type={type} preference={preference} />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </>
  );
}

function Toggle({
  label,
  ariaLabel,
  checked,
  disabled,
}: {
  label: string;
  ariaLabel: string;
  checked: boolean;
  disabled?: boolean;
}) {
  return (
    <label className="flex items-center gap-1.5 text-caption text-n-500">
      <input
        type="checkbox"
        aria-label={ariaLabel}
        defaultChecked={checked}
        disabled={disabled}
        className="size-4"
      />
      {label}
    </label>
  );
}

function DigestSelect({
  type,
  preference,
}: {
  type: NotificationType;
  preference: Preference;
}) {
  return (
    <select
      aria-label={`${type.label} digest`}
      defaultValue={preference.digest}
      className="rounded-sm border border-n-200 bg-n-0 px-1.5 py-1 text-body-sm text-n-900"
    >
      <option value="off">Off</option>
      <option value="daily">Daily</option>
      <option value="weekly">Weekly</option>
    </select>
  );
}
