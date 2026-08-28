/**
 * Bundle budget (docs/01 §8, docs/07 §5).
 *
 * 200 KB gzipped of shared first-load JavaScript. The number is not sacred;
 * having a number that fails the build is. Without one, the bundle grows 15 KB
 * per pull request and nobody notices the moment the app became slow.
 *
 * Raising the budget is allowed — as a deliberate, reviewable change to this
 * file, with a reason in the commit message.
 *
 * Run: node scripts/verify-bundle-budget.mjs   (after `npm run build`)
 */

import { existsSync, readFileSync } from "node:fs";
import { gzipSync } from "node:zlib";

const BUDGET_KB = 200;

const manifestPath = new URL("../.next/build-manifest.json", import.meta.url);

if (!existsSync(manifestPath)) {
  console.error("No build manifest found. Run `npm run build` first.");
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));

/**
 * `rootMainFiles` is the shared client bundle every route pays for. Route-level
 * chunks are measured on top of it; a route is only interesting if its own
 * chunks push the total past the budget.
 */
const gzippedKb = (files) =>
  files
    .filter((file) => file.endsWith(".js"))
    .reduce((total, file) => {
      const path = new URL(`../.next/${file}`, import.meta.url);

      return existsSync(path) ? total + gzipSync(readFileSync(path)).length : total;
    }, 0) / 1024;

const shared = gzippedKb([
  ...(manifest.rootMainFiles ?? []),
  ...(manifest.polyfillFiles ?? []),
]);

console.log(`shared first-load bundle   ${shared.toFixed(1)} KB gzipped`);

let worst = shared;

for (const [route, files] of Object.entries(manifest.pages ?? {})) {
  const total = shared + gzippedKb(files);
  worst = Math.max(worst, total);

  console.log(`${total > BUDGET_KB ? "FAIL" : "ok  "} ${route.padEnd(28)} ${total.toFixed(1)} KB`);
}

if (worst > BUDGET_KB) {
  console.error(`\nWorst route is ${worst.toFixed(1)} KB, over the ${BUDGET_KB} KB budget.`);
  process.exit(1);
}

console.log(`\nWithin budget: worst route ${worst.toFixed(1)} KB of ${BUDGET_KB} KB.`);
