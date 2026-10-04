import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { KeyValue, KeyValueItem, Unset } from "@/components/ui/KeyValue";
import { Panel } from "@/components/ui/Panel";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import type { Locale } from "@/i18n/config";
import { employmentName, personStatusName } from "@/i18n/labels";
import { translator, type Translator } from "@/i18n/translate";
import { formatDate } from "@/lib/format";
import { WorkloadPanel } from "./WorkloadPanel";
import type { PersonDetail, PersonRef, Workload } from "./types";

/**
 * One person's profile (docs/08 §2, ADR 0024).
 *
 * Ordered by the questions people actually arrive with: who is this, who do
 * they work with, and what are they doing right now. Employment administrivia
 * sits last because it is the rarest question, and a profile that leads with an
 * employee number reads like an HR record rather than a colleague.
 *
 * Rebuilt on the shared primitives, because the old version demonstrated the
 * two faults ADR 0024 is about at once: it centred itself in `max-w-4xl` while
 * the role and denial sections beneath it ran the full window — one page, two
 * left edges — and it printed eight one-line facts down a column on a display
 * wide enough for four of them side by side. It looked sparse and crowded
 * simultaneously, which is what missing structure looks like.
 *
 * Their open work is shown, never their completed work: this page exists to
 * answer "what is this person on", and a scrollback of finished items is the
 * beginning of using a profile to appraise someone (docs/02 §11). The list is
 * bounded and has no "see all" link on purpose — the API applies the caller's
 * own visibility, so what is missing from it is work the viewer may not see,
 * and a link promising the rest would be a promise the server will not keep.
 */
export function PersonIdentity({ person, locale = "en" }: { person: PersonDetail; locale?: Locale }) {
  const t = translator(locale);

  return (
    <header className="flex items-start gap-4 rounded-xl border border-n-300 bg-n-0 px-4 py-3.5">
      <Avatar id={person.id} name={person.name} size="lg" />

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-h1 font-semibold text-n-900">{person.name}</h1>

          {/* Deactivated and erased people stay reachable by link — a work item
              assigned last month still names them — so the state belongs here
              rather than only as an absence from the directory. */}
          {person.erased_at !== null ? (
            <Badge tone="danger" icon="cross" solid>
              {t("profile.erased")}
            </Badge>
          ) : (
            person.status !== "active" && <Badge>{personStatusName(person.status, t)}</Badge>
          )}
        </div>

        {/* One line, not four. Job title, department and address are the three
            facts somebody checks they have the right person by, and stacking
            them cost three rows to say what a sentence says. */}
        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-body-sm text-n-500">
          <span className="text-n-700">{person.job_title ?? t("profile.noTitle")}</span>
          {person.department && <span>· {person.department.name}</span>}
          <span>·</span>
          {/* An erased person has no address — the API sends null rather than
              the placeholder stored in `users`, which is a random string at a
              domain reserved so it can never be delivered to (ADR 0022). */}
          {person.email === null ? (
            <span>{t("profile.noAddress")}</span>
          ) : (
            <a href={`mailto:${person.email}`} className="text-a-700 hover:underline">
              {person.email}
            </a>
          )}
        </p>

        {person.roles.length > 0 && (
          <ul className="mt-2 flex flex-wrap gap-1.5">
            {person.roles.map((role) => (
              <li key={role.id}>
                <Badge>{role.name}</Badge>
              </li>
            ))}
          </ul>
        )}
      </div>
    </header>
  );
}

export function PersonWork({
  openWork,
  timeZone,
  locale = "en",
}: {
  openWork: WorkItem[];
  timeZone: string;
  locale?: Locale;
}) {
  const t = translator(locale);

  return (
    <Panel
      id="open-work"
      title={t("items.open.title")}
      description={
        openWork.length === 0 ? t("profile.nothingOpen") : t.plural("profile.openCount", openWork.length)
      }
      bleed
    >
      {openWork.length === 0 ? (
        <p className="px-4 py-3 text-body-sm text-n-500">
          {t("profile.noOpen")}
        </p>
      ) : (
        <div className="divide-y divide-n-100">
          {openWork.map((item) => (
            <WorkItemRow key={item.id} item={item} timeZone={timeZone} locale={locale} />
          ))}
        </div>
      )}
    </Panel>
  );
}

export function PersonEmployment({
  person,
  timeZone,
  locale = "en",
}: {
  person: PersonDetail;
  timeZone: string;
  locale?: Locale;
}) {
  const t = translator(locale);

  return (
    <Panel id="employment" title={t("profile.employment")}>
      <KeyValue>
        <KeyValueItem label={t("plist.col.capacity")}>
          {person.weekly_capacity_hours ? (
            t("profile.perWeek", { hours: parseFloat(person.weekly_capacity_hours) })
          ) : (
            <Unset />
          )}
        </KeyValueItem>

        <KeyValueItem label={t("tpl.type")}>
          {person.employment_type ? employmentName(person.employment_type, t) : <Unset />}
        </KeyValueItem>

        <KeyValueItem label={t("profile.location")}>{person.work_location || <Unset />}</KeyValueItem>

        <KeyValueItem label={t("plist.col.joined")}>
          {formatDate(person.hired_at ?? person.joined_at, timeZone, locale)}
        </KeyValueItem>

        {person.employee_number != null && (
          <KeyValueItem label={t("profile.employeeNo")}>
            <span className="font-mono">{person.employee_number}</span>
          </KeyValueItem>
        )}
      </KeyValue>
    </Panel>
  );
}

/** The glance column: who they answer to, and how full their week is. */
export function PersonAside({
  person,
  workload,
  locale = "en",
}: {
  person: PersonDetail;
  workload: Workload | null;
  locale?: Locale;
}) {
  const t = translator(locale);

  return (
    <>
      {workload && (
        <Panel id="this-week" title={t("home.section.thisWeek")}>
          <WorkloadPanel workload={workload} locale={locale} />
        </Panel>
      )}

      <Panel id="reporting-line" title={t("profile.reporting")}>
        <div className="space-y-3">
          <div>
            <p className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
              {t("profile.manager")}
            </p>
            <div className="mt-1">
              {person.manager ? <PersonLink person={person.manager} t={t} /> : <Unset />}
            </div>
          </div>

          <div>
            <p className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
              {t("profile.directReports")}
              {person.direct_reports.length > 0 && (
                <span className="ml-1 font-normal tracking-normal text-n-500">
                  ({person.direct_reports.length})
                </span>
              )}
            </p>
            <div className="mt-1 space-y-1">
              {person.direct_reports.length === 0 ? (
                <Unset />
              ) : (
                person.direct_reports.map((report) => (
                  <div key={report.id}>
                    <PersonLink person={report} t={t} />
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </Panel>
    </>
  );
}

/**
 * A name that may not resolve to a link.
 *
 * `id` is the colleague's membership id, which is exactly what /people/{id}
 * takes — so the reporting line is navigable without the client having to map
 * profile ids onto membership ids.
 */
function PersonLink({ person, t }: { person: PersonRef; t: Translator }) {
  return (
    <Link
      href={`/people/${person.id}`}
      className="inline-flex min-w-0 items-center gap-2 text-body-sm text-n-900 hover:text-a-700"
    >
      <Avatar id={person.id} name={person.name ?? "?"} size="sm" />
      <span className="truncate">{person.name ?? t("row.unknown")}</span>
    </Link>
  );
}
