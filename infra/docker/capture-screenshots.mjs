/**
 * Screenshot harness — visual QC against the docs/09 §8 quality gate.
 *
 * A screen is not "done" because the code compiles. These captures are how the
 * gate is actually applied: does the hierarchy match the importance of the
 * content, is anything a card that has no reason to be one, does it hold up at
 * 375px with real data rather than three tidy rows.
 */

import { chromium } from "playwright";

const CHROME = "/opt/pw-browsers/chromium-1194/chrome-linux/chrome";
const BASE = "http://localhost:3000";

const browser = await chromium.launch({ executablePath: CHROME, args: ["--no-sandbox"] });

async function signIn(context, email) {
  const page = await context.newPage();
  await page.goto(`${BASE}/login`, { waitUntil: "domcontentloaded" });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', "password");
  await Promise.all([page.waitForURL(`${BASE}/`), page.click('button[type="submit"]')]);
  return page;
}

// Sarah is the employee view: assigned work, overdue items, nothing to administer.
const employee = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
const sarah = await signIn(employee, "sarah@acme.test");

for (const [name, path] of [
  ["05-my-work-today", "/my-work?view=today"],
  ["06-my-work-overdue", "/my-work?view=overdue"],
  ["07-work-item-detail", "/work/ENG-142"],
]) {
  await sarah.goto(BASE + path, { waitUntil: "domcontentloaded" });
  await sarah.waitForTimeout(900);
  await sarah.screenshot({ path: `/tmp/${name}.png`, fullPage: name === "07-work-item-detail" });
  console.log("captured", name);
}

// Ahmad is the manager view: projects, the board, other people's work.
const manager = await browser.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 2 });
const ahmad = await signIn(manager, "ahmad@acme.test");

for (const [name, path] of [
  ["08-projects", "/projects"],
  ["09-board", "/projects/ENG/board"],
  // Phase 4: the reviewer's queue. Ahmad is the one with something to decide,
  // so this is the only account where the screen has real content.
  ["12-inbox-reviews", "/inbox?tab=reviews"],
  ["13-inbox-activity", "/inbox?tab=activity"],
  ["14-notification-preferences", "/settings/notifications"],
]) {
  await ahmad.goto(BASE + path, { waitUntil: "domcontentloaded" });
  await ahmad.waitForTimeout(900);
  await ahmad.screenshot({ path: `/tmp/${name}.png` });
  console.log("captured", name);
}

// The phone check that matters: not "does it fit" but "is the interaction
// model right for someone checking and responding" (docs/08 §6).
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
await phone.addCookies(await employee.cookies());
const mobile = await phone.newPage();

for (const [name, path] of [
  ["10-mobile-my-work", "/my-work?view=today"],
  ["11-mobile-work-item", "/work/ENG-142"],
  // A submission waiting on someone else, from the submitter's side: the view
  // that stops work disappearing the moment it is sent.
  ["15-mobile-inbox-waiting", "/inbox?tab=waiting"],
]) {
  await mobile.goto(BASE + path, { waitUntil: "domcontentloaded" });
  await mobile.waitForTimeout(900);
  await mobile.screenshot({ path: `/tmp/${name}.png` });
  console.log("captured", name);
}

await browser.close();
