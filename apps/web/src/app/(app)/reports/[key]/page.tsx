import Link from "next/link";
import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { ExportPanel } from "@/features/report/ExportPanel";
import { listExports } from "@/features/report/actions";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale, type Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * Any of the four reports (ADR 0011).
 *
 * One page rather than four, because the API is already generic: a report is a
 * key, a list of columns and rows in that order, and the registry has held four
 * of them since `1ae654d`. Only `organization` was ever reachable — the other
 * three were complete, tested and had no screen at all, which is the ninth time
 * this project has found something built and unreachable.
 *
 * Writing four bespoke screens would have meant four copies of "what columns
 * does this report have", drifting from the builders that actually decide. The
 * columns come from the response; so does the list of parameters the report
 * cannot be built without.
 */
const KNOWN = new Set(["project", "team", "personal", "organization"]);

type Catalogue = Array<{ key: string; columns: string[]; requires: string[] }>;

export default async function ReportPage({
  params,
  searchParams,
}: {
  params: Promise<{ key: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  // Awaited for the redirect it performs, not for a value: this page renders
  // nothing per-person, and binding an unused `me` would suggest it did.
  const [me, { key }, rawQuery] = await Promise.all([requireUser(), params, searchParams]);
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // Only the single-valued parameters. A report takes named scalars; an array
  // in the URL is a client mistake, and silently using its first element would
  // build a report from an input nobody asked for.
  const parameters = Object.fromEntries(
    Object.entries(rawQuery).filter((entry): entry is [string, string] => typeof entry[1] === "string"),
  );

  const { data: catalogue } = await api<Catalogue>("/reports/catalogue");
  const definition = catalogue.find((report) => report.key === key);

  if (!definition) notFound();

  const missing = definition.requires.filter((name) => !(name in parameters));

  // Asked before the report is fetched, so a missing parameter says which one
  // rather than arriving as a 404 that reads like "this report does not exist".
  if (missing.length > 0) {
    return (
      <div className="mx-auto max-w-4xl space-y-5">
        <Heading reportKey={key} locale={locale} />
        <EmptyState
          title={t("rep.needs.title", { params: missing.join(t("rep.and")) })}
          description={t("rep.needs.body", { param: missing[0] ?? "", key })}
        />
      </div>
    );
  }

  const query = new URLSearchParams(parameters);

  let rows: Array<Array<string | number | boolean | null>>;
  let meta: { columns: string[]; hidden_count: number };

  try {
    const response = await api<Array<Array<string | number | boolean | null>>>(
      `/reports/${key}?${query}`,
    );

    rows = response.data;
    meta = response.meta as unknown as { columns: string[]; hidden_count: number };
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  const exports = await listExports();

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <Heading reportKey={key} locale={locale} />

      {rows.length === 0 ? (
        <EmptyState
          title={t("rep.empty.title")}
          description={t("rep.empty.body")}
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-body-sm">
            <thead>
              <tr className="border-b border-n-200 text-left">
                {meta.columns.map((column) => (
                  <th
                    key={column}
                    scope="col"
                    className="px-2 py-2 text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
                  >
                    {column.replace(/_/g, " ")}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={index} className="border-b border-n-100 last:border-b-0">
                  {row.map((cell, column) => (
                    <td
                      key={meta.columns[column] ?? column}
                      className="px-2 py-1.5 align-top text-n-900"
                    >
                      {/* Null is an empty cell, not the word "null" and not a
                          zero. Zero is a claim; absent is an absence, and four
                          ADRs in this phase turn on the difference. */}
                      {cell === null ? <span className="text-n-300">—</span> : String(cell)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta.hidden_count > 0 && (
        <p className="max-w-[72ch] text-caption text-s-active">
          {t.plural("rep.hidden", meta.hidden_count)}
        </p>
      )}

      <ExportPanel
        reportKey={key}
        parameters={parameters}
        exports={exports.exports}
        formats={exports.formats}
      />

      <p className="max-w-[72ch] text-caption text-n-500">
        {t("rep.footer")}
      </p>
    </div>
  );
}

function Heading({ reportKey, locale }: { reportKey: string; locale: Locale }) {
  const t = translator(locale);
  const known = KNOWN.has(reportKey);

  return (
    <div className="space-y-3">
      <Link href="/reports" className="text-body-sm text-n-500 hover:text-a-700">
        {t("rep.back")}
      </Link>

      <PageHeader
        title={
          known
            ? t(`rep.title.${reportKey}` as MessageKey)
            : t("rep.title.other", { key: reportKey })
        }
        description={known ? t(`rep.desc.${reportKey}` as MessageKey) : ""}
      />
    </div>
  );
}
