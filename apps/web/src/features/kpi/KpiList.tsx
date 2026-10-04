import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import type { Locale } from "@/i18n/config";
import type { Translator } from "@/i18n/translate";
import { formatKpiValue, STATUS_TONE, statusLabel } from "./format";
import type { Kpi } from "./types";

/**
 * KPIs as a short list, one line each: the name, its value this period and
 * the status in words. For Home, where a tile per KPI would be a wall.
 */
export function KpiList({ kpis, t, locale }: { kpis: Kpi[]; t: Translator; locale: Locale }) {
  return (
    <ul className="divide-y divide-n-100">
      {kpis.map((kpi) => (
        <li key={kpi.id} className="flex items-center gap-2 px-4 py-2">
          <Link href={`/kpis/${kpi.id}`} className="min-w-0 flex-1 truncate text-body-sm font-medium text-n-900 hover:underline">
            {kpi.name}
          </Link>
          <span className="shrink-0 text-body-sm tabular-nums text-n-700">
            {formatKpiValue(kpi.current.value, kpi.unit, t, locale)}
          </span>
          <Badge tone={STATUS_TONE[kpi.current.status]}>{statusLabel(kpi.current.status, t)}</Badge>
        </li>
      ))}
    </ul>
  );
}
