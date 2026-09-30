import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

/**
 * docs/11 §4: "Accessibility assertions (axe-core) run against every screen
 * these flows touch."
 *
 * Serious and critical only. `moderate` and `minor` are real, but a suite that
 * fails on every best-practice note is a suite people learn to rerun until it
 * passes; the two levels kept are the ones where somebody using a screen
 * reader or a keyboard cannot do the thing at all.
 *
 * WCAG 2.1 A and AA, which is what the design system claims (docs/09 §2).
 *
 * The failure names each rule, how many nodes, and the first few selectors —
 * the three things needed to find it without rerunning with a debugger.
 */
export async function expectAccessible(page: Page, screen: string): Promise<void> {
  const report = await accessibilityReport(page);

  expect(report, `${screen} has accessibility violations:\n${report.join("\n")}`).toEqual([]);
}

/**
 * The same check, returned instead of asserted — for a walk over many screens
 * that should report every failing screen in one run, not stop at the first.
 */
export async function accessibilityReport(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();

  const blocking = violations.filter(
    (violation) => violation.impact === "serious" || violation.impact === "critical",
  );

  const report = blocking.map(
    (violation) =>
      `${violation.id} (${violation.impact}, ${violation.nodes.length}×): ${violation.help}\n    `
      + violation.nodes
        .slice(0, 3)
        .map((node) => node.target.join(" "))
        .join("\n    "),
  );

  return report;
}
