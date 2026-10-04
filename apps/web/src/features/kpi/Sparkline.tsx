import type { KpiPeriodValue } from "./types";

/**
 * The recent trend of one KPI, with its target as a dashed reference line.
 *
 * Neutral ink, not the accent: the accent is reserved for what can be clicked
 * (docs/09), and the status beside the chart already carries the judgement —
 * in words, never in the line's colour.
 *
 * One series, so no legend: the tile's title names it. A 2px line, markers on
 * each period that has a value (a gap where it has none — never a zero that
 * was not measured), and the current period hollow because it is still
 * running. Each point carries a native tooltip with its period and value; the
 * KPI's own page has the same numbers as a table.
 */
export function Sparkline({
  history,
  target,
  labels,
  width = 160,
  height = 40,
}: {
  history: KpiPeriodValue[];
  target: number;
  /** Per point: "October 2026: 4 releases". */
  labels: string[];
  width?: number;
  height?: number;
}) {
  const values = history.map((point) => point.value).filter((value): value is number => value !== null);

  if (values.length === 0) return null;

  const pad = 5;
  const low = Math.min(target, ...values);
  const high = Math.max(target, ...values);
  const span = high - low || 1;
  const step = history.length > 1 ? (width - pad * 2) / (history.length - 1) : 0;

  const x = (index: number) => pad + index * step;
  const y = (value: number) => pad + (1 - (value - low) / span) * (height - pad * 2);

  // Broken where a period has no value.
  const segments: string[] = [];
  let current = "";

  history.forEach((point, index) => {
    if (point.value === null) {
      if (current !== "") segments.push(current);
      current = "";
      return;
    }

    current += `${current === "" ? "M" : "L"}${x(index).toFixed(1)},${y(point.value).toFixed(1)}`;
  });

  if (current !== "") segments.push(current);

  return (
    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true" className="overflow-visible">
      <line
        x1={pad}
        x2={width - pad}
        y1={y(target)}
        y2={y(target)}
        stroke="var(--color-n-500)"
        strokeWidth={1}
        strokeDasharray="3 3"
      />
      {segments.map((d) => (
        <path key={d} d={d} fill="none" stroke="var(--color-n-700)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
      ))}
      {history.map((point, index) =>
        point.value === null ? null : (
          <circle
            key={point.period_start}
            cx={x(index)}
            cy={y(point.value)}
            r={point.partial ? 3.5 : 3}
            fill={point.partial ? "var(--color-n-0)" : "var(--color-n-700)"}
            stroke="var(--color-n-700)"
            strokeWidth={point.partial ? 2 : 0}
          >
            <title>{labels[index]}</title>
          </circle>
        ),
      )}
    </svg>
  );
}
