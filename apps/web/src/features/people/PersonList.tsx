import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Unset } from "@/components/ui/KeyValue";
import type { Locale } from "@/i18n/config";
import { employmentName } from "@/i18n/labels";
import { translator, type Translator } from "@/i18n/translate";
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
export function PersonList({
  people,
  timeZone,
  locale = "en",
}: {
  people: Person[];
  timeZone: string;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);

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

              <CapacityLabel person={person} t={t} className="shrink-0 text-right" />
            </Link>
          </li>
        ))}
      </ul>

      {/* ── Desktop: comparison table ─────────────────────────────────────
          On the shared primitives now (ADR 0024). It had its own `Th` and `Td`
          at the bottom of this file — the third private copy of a table in the
          product, each with slightly different padding, which is what happens
          when the system has no table to reach for. */}
      <div className="hidden md:block">
        <DataTable caption={t("plist.caption")}>
          <THead>
            <Tr>
              <Th>{t("projects.col.name")}</Th>
              <Th>{t("plist.col.jobTitle")}</Th>
              <Th>{t("pnew.department")}</Th>
              <Th align="right">{t("plist.col.capacity")}</Th>
              <Th>{t("plist.col.joined")}</Th>
            </Tr>
          </THead>
          <TBody>
            {people.map((person) => (
              <Tr key={person.id}>
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
                <Td>{person.job_title ?? <Unset />}</Td>
                <Td muted>{person.department?.name ?? <Unset />}</Td>
                <Td align="right">
                  <CapacityLabel person={person} t={t} />
                </Td>
                <Td muted>
                  <span className="whitespace-nowrap">
                    {formatDate(person.joined_at, timeZone, locale)}
                  </span>
                </Td>
              </Tr>
            ))}
          </TBody>
        </DataTable>
      </div>
    </>
  );
}

/**
 * Part-time and contract capacity is stated, never left to be assumed as 40.
 * A workload denominator that silently defaults is how a manager ends up
 * over-committing a part-time colleague (docs/02 §11).
 */
function CapacityLabel({
  person,
  t,
  className = "",
}: {
  person: Person;
  t: Translator;
  className?: string;
}) {
  if (!person.weekly_capacity_hours)
    return (
      <span className={className}>
        <Unset />
      </span>
    );

  const hours = parseFloat(person.weekly_capacity_hours);
  const isStandard = person.employment_type === "full_time";

  return (
    <span className={className}>
      <span className="whitespace-nowrap text-body-sm">{t("time.hours", { hours })}</span>
      {!isStandard && (
        <span className="ml-1.5 align-middle">
          <Badge>{person.employment_type ? employmentName(person.employment_type, t) : null}</Badge>
        </span>
      )}
    </span>
  );
}
