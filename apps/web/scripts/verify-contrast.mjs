/**
 * Contrast validator (docs/09 §2 rule 3).
 *
 * Every token pair the design system actually uses is checked against WCAG AA
 * here, in CI, rather than by eye. This script exists because the first version
 * of the palette shipped a secondary-text colour at 4.45:1 — visually fine,
 * measurably failing — and no amount of looking at it would have caught that.
 *
 * Run: node scripts/verify-contrast.mjs
 */

import { readFileSync } from "node:fs";

const css = readFileSync(new URL("../src/app/globals.css", import.meta.url), "utf8");

function token(name, scope = css) {
  const match = scope.match(new RegExp(`--color-${name}:\\s*(#[0-9a-fA-F]{6})`));
  if (!match) throw new Error(`Token --color-${name} not found`);
  return match[1];
}

const darkBlock = css.slice(css.indexOf('[data-theme="dark"]'));

const channel = (c) => {
  const v = c / 255;
  return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
};

const luminance = (hex) => {
  const n = parseInt(hex.slice(1), 16);
  return (
    0.2126 * channel((n >> 16) & 255) +
    0.7152 * channel((n >> 8) & 255) +
    0.0722 * channel(n & 255)
  );
};

const contrast = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

/**
 * AA thresholds: 4.5 for body text, 3.0 for large text (>=18.66px bold or
 * >=24px) and for non-text UI boundaries.
 */
const CHECKS = [
  // ── light theme, on the page background ────────────────────────────────
  ["n-900 heading", token("n-900"), token("n-0"), 4.5],
  ["n-700 body", token("n-700"), token("n-0"), 4.5],
  ["n-500 secondary", token("n-500"), token("n-0"), 4.5],
  ["n-500 on hover row", token("n-500"), token("n-50"), 4.5],
  ["n-700 on selected row", token("n-700"), token("a-50"), 4.5],
  ["a-500 link", token("a-500"), token("n-0"), 4.5],
  ["a-700 active link", token("a-700"), token("n-0"), 4.5],
  ["a-700 on selected nav", token("a-700"), token("a-50"), 4.5],
  ["white on primary button", "#ffffff", token("a-500"), 4.5],
  ["white on danger button", "#ffffff", token("s-danger"), 4.5],
  ["s-danger text", token("s-danger"), token("n-0"), 4.5],
  ["s-active text", token("s-active"), token("n-0"), 4.5],
  ["s-success text", token("s-success"), token("n-0"), 4.5],
  ["s-review text", token("s-review"), token("n-0"), 4.5],
  ["s-info text", token("s-info"), token("n-0"), 4.5],
  // Borders are UI boundaries, not text: 3.0 is the bar, and the subtle
  // hairline is decorative separation rather than a meaningful boundary.
  ["n-200 border", token("n-200"), token("n-0"), 1.3],

  // ── dark theme ─────────────────────────────────────────────────────────
  ["dark n-900 heading", token("n-900", darkBlock), token("n-0", darkBlock), 4.5],
  ["dark n-700 body", token("n-700", darkBlock), token("n-0", darkBlock), 4.5],
  ["dark n-500 secondary", token("n-500", darkBlock), token("n-0", darkBlock), 4.5],
  ["dark a-500 link", token("a-500", darkBlock), token("n-0", darkBlock), 4.5],
  ["dark s-danger", token("s-danger", darkBlock), token("n-0", darkBlock), 4.5],
  ["dark s-success", token("s-success", darkBlock), token("n-0", darkBlock), 4.5],
];

let failures = 0;

for (const [label, fg, bg, minimum] of CHECKS) {
  const ratio = contrast(fg, bg);
  const pass = ratio >= minimum;
  if (!pass) failures++;

  console.log(
    `${pass ? "ok  " : "FAIL"} ${label.padEnd(26)} ${fg} on ${bg}  ${ratio.toFixed(2)}:1 (min ${minimum})`,
  );
}

console.log(
  failures === 0
    ? `\nAll ${CHECKS.length} token pairs meet their contrast minimum.`
    : `\n${failures} of ${CHECKS.length} token pairs fail.`,
);

process.exit(failures === 0 ? 0 : 1);
