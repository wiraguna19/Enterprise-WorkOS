/**
 * Accessibility gate (docs/07 §6, docs/09 §8).
 *
 * Runs axe-core against every screen with real seeded data. Not a launch audit
 * — a build gate, because retrofitting accessibility is far more expensive than
 * not breaking it, and the palette regression this suite caught in Phase 2 is
 * the reason it exists.
 */

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const CHROME = "/opt/pw-browsers/chromium-1194/chrome-linux/chrome";
const BASE = "http://localhost:3000";

const browser = await chromium.launch({ executablePath: CHROME, args: ["--no-sandbox"] });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

await page.goto(`${BASE}/login`, { waitUntil: "domcontentloaded" });

let failures = 0;

async function scan(label, path) {
  await page.goto(BASE + path, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(700);

  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();

  if (violations.length === 0) {
    console.log(`ok   ${label.padEnd(26)} 0 violations`);
    return;
  }

  failures += violations.length;
  console.log(`FAIL ${label.padEnd(26)} ${violations.length} violations`);
  for (const v of violations) {
    console.log(`       [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} nodes)`);
    console.log(`         e.g. ${v.nodes[0]?.html?.slice(0, 100)}`);
  }
}

await scan("login", "/login");

await page.fill('input[name="email"]', "ahmad@acme.test");
await page.fill('input[name="password"]', "password");
await Promise.all([page.waitForURL(`${BASE}/`), page.click('button[type="submit"]')]);

await scan("home", "/");
await scan("my work — today", "/my-work?view=today");
await scan("my work — completed", "/my-work?view=completed");
await scan("projects", "/projects");
await scan("board", "/projects/ENG/board");
await scan("work item detail", "/work/ENG-142");
await scan("people", "/people");
await scan("inbox — reviews", "/inbox?tab=reviews");
await scan("inbox — waiting", "/inbox?tab=waiting");
await scan("inbox — activity", "/inbox?tab=activity");
await scan("notification prefs", "/settings/notifications");

await browser.close();

console.log(
  failures === 0
    ? "\nAll screens pass WCAG 2.1 AA with real data."
    : `\n${failures} accessibility violations.`,
);

process.exit(failures === 0 ? 0 : 1);
