import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { formatDate } from "@/lib/format";
import { WorkloadPanel } from "./WorkloadPanel";
import type { PersonDetail, PersonRef, Workload } from "./types";

/**
 * One person's profile (docs/08 §2).
 *
 * Ordered by the questions people actually arrive with: who is this, who do
 * they work with, and what are they doing right now. Employment administrivia
 * sits last because it is the rarest question, and a profile that leads with
 * an employee number reads like an HR record rather than a colleague.
 *
 * Their open work is shown, never their completed work: this page exists to
 * answer "what is this person on", and a scrollback of finished items is the
 * beginning of using a profile to appraise someone (docs/02 §11). The list is
 * bounded and has no "see all" link on purpose — the API applies the caller's
 * own visibility, so what is missing from it is work the viewer may not see,
 * and a link promising the rest would be a promise the server will not keep.
 */
export function PersonProfile({
  person,
  openWork,
  workload,
  timeZone,
}: {
  person: PersonDetail;
  openWork: WorkItem[];
  workload: Workload | null;
  timeZone: string;
}) {
  return (
    <div className="mx-auto max-w-4xl space-y-8">
      <header className="flex items-start gap-4 border-b border-n-100 pb-5">
        <Avatar id={person.id} name={person.name} size="lg" />

        <div className="min-w-0 flex-1">
          <h1 className="text-display font-semibold text-n-900">{person.name}</h1>

          <p className="mt-0.5 text-body text-n-500">
            {person.job_title ?? "No job title"}
            {person.department && <span> · {person.department.name}</span>}
          </p>

          <p className="mt-1 text-body-sm">
            <a href={`mailto:${person.email}`} className="text-a-700 hover:underline">
              {person.email}
            </a>
          </p>
        </div>

        {/* Deactivated people stay reachable by link — a work item assigned last
            month still names them — so the state has to be visible here rather
            than only as an absence from the directory. */}
        {person.status !== "active" && (
          <span className="shrink-0 rounded-full bg-n-100 px-2 py-0.5 text-caption text-n-600">
            {person.status}
          </span>
        )}
      </header>

      <Section title="Reporting line">
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Dt>Manager</Dt>
            <dd className="mt-1">
              {person.manager ? <PersonLink person={person.manager} /> : <Muted>—</Muted>}
            </dd>
          </div>

          <div>
            <Dt>
              Direct reports
              {person.direct_reports.length > 0 && (
                <span className="ml-1 font-normal normal-case tracking-normal text-n-400">
                  ({person.direct_reports.length})
                </span>
              )}
            </Dt>
            <dd className="mt-1 space-y-1">
              {person.direct_reports.length === 0 ? (
                <Muted>—</Muted>
              ) : (
                person.direct_reports.map((report) => (
                  <div key={report.id}>
                    <PersonLink person={report} />
                  </div>
                ))
              )}
            </dd>
          </div>
        </dl>
      </Section>

      {workload && (
        <Section title="This week">
          <WorkloadPanel workload={workload} />
        </Section>
      )}

      <Section title="Open work">
        {openWork.length === 0 ? (
          <Muted>Nothing open.</Muted>
        ) : (
          <div className="border-t border-n-100">
            {openWork.map((item) => (
              <WorkItemRow key={item.id} item={item} timeZone={timeZone} />
            ))}
          </div>
        )}
      </Section>

      <Section title="Employment">
        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
          <Detail label="Capacity">
            {person.weekly_capacity_hours ? (
              `${parseFloat(person.weekly_capacity_hours)} h / week`
            ) : (
              <Muted>—</Muted>
            )}
          </Detail>

          <Detail label="Type">
            {person.employment_type?.replace("_", " ") ?? <Muted>—</Muted>}
          </Detail>

          <Detail label="Location">{person.work_location ?? <Muted>—</Muted>}</Detail>

          <Detail label="Joined">{formatDate(person.hired_at ?? person.joined_at, timeZone)}</Detail>

          {person.employee_number != null && (
            <Detail label="Employee no.">
              <span className="font-mono">{person.employee_number}</span>
            </Detail>
          )}
        </dl>
      </Section>

      {person.roles.length > 0 && (
        <Section title="Access">
          <ul className="flex flex-wrap gap-1.5">
            {person.roles.map((role) => (
              <li
                key={role.id}
                className="rounded-full border border-n-200 px-2 py-0.5 text-caption text-n-600"
              >
                {role.name}
              </li>
            ))}
          </ul>
        </Section>
      )}
    </div>
  );
}

/**
 * A name that may not resolve to a link.
 *
 * `id` is the colleague's membership id, which is exactly what /people/{id}
 * takes — so the reporting line is navigable without the client having to map
 * profile ids onto membership ids.
 */
function PersonLink({ person }: { person: PersonRef }) {
  return (
    <Link
      href={`/people/${person.id}`}
      className="inline-flex items-center gap-2 text-body-sm text-n-900 hover:text-a-700"
    >
      <Avatar id={person.id} name={person.name ?? "?"} size="sm" />
      <span className="truncate">{person.name ?? "Unknown"}</span>
      {person.job_title && (
        <span className="truncate text-caption text-n-500">{person.job_title}</span>
      )}
    </Link>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2">
      <h2 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">{title}</h2>
      {children}
    </section>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <Dt>{label}</Dt>
      <dd className="mt-1 text-body-sm text-n-900">{children}</dd>
    </div>
  );
}

function Dt({ children }: { children: React.ReactNode }) {
  return (
    <dt className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">{children}</dt>
  );
}

function Muted({ children }: { children: React.ReactNode }) {
  return <span className="text-n-400">{children}</span>;
}
