import { expect } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 6 — "Employee: comment with a @mention, attach a file".
 *
 * One sentence in the spec, and until today neither half of it worked. The
 * mention wrote a row nobody read — `comment.mentioned` had a sentence in the
 * resource, a toggle in settings, and no code path that produced one — and the
 * attachment slice had no interface at all. Both were complete on the server
 * and invisible from the product, which is this codebase's oldest defect.
 *
 * The two halves are tested together because the flow is one: a person says
 * "here is the plan, @reviewer" and the file and the sentence arrive as one
 * message. Splitting them would test two features and not the thing people do.
 *
 * The mention is made through the PICKER, not by typing a name that happens to
 * be right. Typing "@Ahmad Rizal" would prove the parser and nothing about the
 * screen — and the screen is where the old failure lived: you had to know how
 * the product spelled somebody.
 */
const SARAH = "sarah@acme.test";
const AHMAD = "ahmad@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type Project = { id: string; key: string };
type WorkItem = { id: string; reference: string };

type Attachment = {
  id: string;
  attached_by: string | null;
  file: { id: string; name: string; size_bytes: number; available: boolean; scan_status: string };
};

test.describe("saying something and attaching something", () => {
  test("a mention reaches the person, and the file arrives with it", async ({
    browser,
    viewport,
  }) => {
    const sarahsScreen = await signedInPhone(browser, SARAH, viewport);
    const sarah = sarahsScreen.session;
    const page = sarahsScreen.page;

    const ahmad = await personByEmail(sarah, AHMAD);
    const item = await anItem(sarah);

    await page.goto(`/work/${item.reference}`);

    // ── The mention, made the way the picker makes it ──────────────────────
    // By its label, not its role: the composer is a `combobox` now that it owns
    // the mention list, so `getByRole("textbox")` would not find it — the same
    // control, renamed by an accessibility improvement.
    const composer = page.getByLabel("Write a comment");

    await composer.click();
    await composer.fill("The rollback plan is attached, @Ahm");

    const name = ahmad.name ?? "";

    expect(name, "The seeded colleague has no name to be offered by.").not.toBe("");

    // An empty name would build an empty regex, which matches every option —
    // the assertion below would then pass on whoever happened to be first.
    const option = page.getByRole("option", { name: new RegExp(name, "i") });

    await expect(
      option,
      "The picker offered nothing for a prefix of a real colleague's name. Before it "
        + "existed, mentioning somebody meant knowing exactly how the product spells "
        + "them — which is the failure this control removes.",
    ).toBeVisible();

    await option.click();

    // What the picker inserted must be what the server resolves: the full
    // display name, verbatim. Asserting the TEXT here rather than only the
    // outcome is deliberate — a mention that resolves for the wrong reason
    // (say, a lucky prefix) would pass the notification check below.
    await expect(composer).toHaveValue(`The rollback plan is attached, @${name} `);

    await page.getByRole("button", { name: "Send" }).click();

    await expect(page.getByText("The rollback plan is attached,")).toBeVisible();

    // ── and it reaches him ─────────────────────────────────────────────────
    const ahmadsSession = (await signedInPhone(browser, AHMAD, viewport)).session;

    const told = await eventually(
      "the mentioned person to be notified",
      async () => {
        const rows = await call<
          Array<{ type: string; subject: { reference: string | null }; message: string }>
        >(ahmadsSession, "/notifications?limit=50");

        return rows.find(
          (row) => row.type === "comment.mentioned" && row.subject.reference === item.reference,
        ) ?? null;
      },
      // Not the queue: `CommentService` dispatches this in the same process as
      // the request (ADR 0013). If it is missing, either the mention did not
      // resolve to a membership or the dispatcher dropped the recipient.
      "Mentions are dispatched in-process, not queued.",
    );

    // The sentence names the work. Every notification in this product read
    // "an item" until `da26a7a`, because the inbox read a field the API does
    // not send.
    expect(told.message).toContain(item.reference);

    // ── The file, through the picker that did not exist ────────────────────
    await page.getByLabel("Attach a file").setInputFiles({
      name: "rollback-plan.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("1. stop the workers\n2. restore the snapshot\n"),
    });

    const attached = await eventually(
      "the file to be attached to the work item",
      async () => {
        const rows = await call<Attachment[]>(sarah, `/work-items/${item.reference}/attachments`);

        return rows.find((row) => row.file.name === "rollback-plan.txt") ?? null;
      },
      "The upload is three steps and only the middle one leaves this app: the API "
        + "signs a URL, the BROWSER puts the bytes into storage, then the file is "
        + "completed and attached. If nothing is attached, check whether MinIO is up "
        + "(`docker compose -f infra/docker/docker-compose.yml up -d minio`) — a "
        + "refused PUT looks identical to a refused attach from here.",
    );

    expect(attached.file.size_bytes).toBeGreaterThan(0);

    // The list names it, and says who put it there.
    await expect(page.getByText("rollback-plan.txt")).toBeVisible();

    // ── and it becomes readable once it has been checked ───────────────────
    //
    // The scan is the one queued step, and it runs on the `low` queue. A worker
    // started without --queue takes `default` only, which left every attachment
    // ever uploaded stuck at "being checked" — a dormant queue wearing a broken
    // feature's clothes.
    const scanned = await eventually(
      "the uploaded file to pass its scan",
      async () => {
        const rows = await call<Attachment[]>(sarah, `/work-items/${item.reference}/attachments`);
        const row = rows.find((candidate) => candidate.file.name === "rollback-plan.txt");

        return row?.file.available === true ? row : null;
      },
      "ScanUploadedFile runs on the `low` queue. `php artisan queue:work` with no "
        + "--queue takes `default` only, so the file stays `pending` forever and is "
        + "deliberately not downloadable. Run: php artisan queue:work --queue=default,low",
    );

    expect(scanned.file.scan_status).toBe("skipped");

    // Openable now, and only now — the state means something.
    //
    // Given longer than the default: the panel re-checks every three seconds
    // after an upload, because the list is server-rendered and the scan happens
    // on a queue. This assertion failed the first time for exactly that reason
    // — the row was correct in the database and stale on the screen, and the
    // fix belonged in the product rather than in a `page.reload()` here.
    await expect(page.getByRole("button", { name: "rollback-plan.txt" })).toBeVisible({
      timeout: 20_000,
    });
  });
});

async function personByEmail(session: Session, email: string): Promise<Person> {
  const people = await call<Person[]>(session, "/people?limit=200");
  const person = people.find((candidate) => candidate.email === email);

  if (!person) {
    throw new Error(`No seeded person with the email ${email}.`);
  }

  return person;
}

/** Arranged through the API: making the item is not what this flow is about. */
async function anItem(session: Session): Promise<WorkItem> {
  const projects = await call<Project[]>(session, "/projects?limit=200");
  const project = projects.find((candidate) => candidate.key === "ENG");

  if (!project) {
    throw new Error("The seeded project ENG is not there, and this flow will not take another.");
  }

  return call<WorkItem>(session, "/work-items", {
    method: "POST",
    body: { title: `E2E comment and attach ${Date.now()}`, project_id: project.id, type: "task" },
  });
}
