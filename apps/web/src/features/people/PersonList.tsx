import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { formatDate } from "@/lib/format";
import type { Person } from "./types";

/**
 * Two layouts, one dataset (docs/08 §6).
 *
 * A table below `md` is the mistake this component exists to avoid: columns
 * either overflow off-screen or wrap into unreadable stacks. On a phone the
 * same rows render as a scannable list where the person's name leads and
 * everything else is secondary metadata on one line.
 *
 * This is not "responsive" in the sense of shrinking; it is a different
 * interaction model for a different question. On desktop the user compares
 * people across columns. On a phone they look someone up.
 *
 * The whole row is the target on a phone and only the name is on desktop: a
 * table row that swallows every click makes the capacity column unselectable,
 * which is the one number a manager wants to copy out of here.
 */
export function PersonList({ people, timeZone }: { people: Person[]; timeZone: string }) {
  return (
    <>
      {/* ── Phone: stacked list ─────────────────────────────────────────── */}
      <ul className="divide-y divide-n-100 border-y border-n-100 md:hidden">
        {people.map((person) => (
          <li key={person.id}>
            <Link href={`/people/${person.id}`} className="flex items-center gap-3 py-2.5">
              <Avatar id={person.id} name={person.name} size="lg" />

              <div className="min-w-0 flex-1">
                <div className="truncate font-medium text-n-900">{person.name}</div>
                <div className="truncate text-caption text-n-500">
                  {person.job_title ?? "—"}
                  {person.department && <span> · {person.department.name}</span>}
                </div>
              </div>

              <CapacityLabel person={person} className="shrink-0 text-right" />
            </Link>
          </li>
        ))}
      </ul>

      {/* ── Desktop: comparison table ───────────────────────────────────── */}
      <table className="hidden w-full border-collapse text-body md:table">
        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>Name</Th>
            <Th>Role</Th>
            <Th>Department</Th>
            <Th className="text-right">Capacity</Th>
            <Th>Joined</Th>
          </tr>
        </thead>
        <tbody>
          {people.map((person) => (
            <tr
              key={person.id}
              className="border-b border-n-100 transition-colors duration-[120ms] hover:bg-n-25"
            >
              <Td>
                <Link
                  href={`/people/${person.id}`}
                  className="flex items-center gap-2 hover:text-a-700"
                >
                  <Avatar id={person.id} name={person.name} />
                  <div className="min-w-0">
                    <div className="truncate font-medium text-n-900">{person.name}</div>
                    <div className="truncate text-caption text-n-500">{person.email}</div>
                  </div>
                </Link>
              </Td>
              <Td>{person.job_title ?? "—"}</Td>
              <Td>{person.department?.name ?? "—"}</Td>
              <Td className="text-right">
                <CapacityLabel person={person} />
              </Td>
              <Td className="whitespace-nowrap text-n-500">
                {formatDate(person.joined_at, timeZone)}
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}

/**
 * Part-time and contract capacity is stated, never left to be assumed as 40.
 * A workload denominator that silently defaults is how a manager ends up
 * over-committing a part-time colleague (docs/02 §11).
 */
function CapacityLabel({ person, className = "" }: { person: Person; className?: string }) {
  if (!person.weekly_capacity_hours) return <span className={className}>—</span>;

  const hours = parseFloat(person.weekly_capacity_hours);
  const isStandard = person.employment_type === "full_time";

  return (
    <span className={className}>
      <span className="whitespace-nowrap text-body-sm">{hours} h</span>
      {!isStandard && (
        <span className="ml-1 whitespace-nowrap text-caption text-n-500">
          {person.employment_type?.replace("_", " ")}
        </span>
      )}
    </span>
  );
}

function Th({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return (
    <th
      scope="col"
      className={`px-3 py-2 text-micro font-semibold uppercase tracking-[0.04em] text-n-500 ${className}`}
    >
      {children}
    </th>
  );
}

function Td({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <td className={`px-3 py-2 align-middle text-body-sm ${className}`}>{children}</td>;
}
