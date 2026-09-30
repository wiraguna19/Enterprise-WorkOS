import Link from "next/link";
import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { BrowseFilters } from "@/features/work-item/browse/BrowseFilters";
import { activeCount, apiQuery, type BrowseParams } from "@/features/work-item/browse/query";
import { BulkSelectList } from "@/features/work-item/browse/BulkSelectList";
import type { WorkVocabulary } from "@/features/work-item/templates";
import type { WorkItem } from "@/features/work-item/types";
import { api, describeApiError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

const PER_PAGE = 50;

/**
 * Browsing every work item the reader may see (docs/05 §4, ADR 0039).
 *
 * This screen did not exist, and its absence was a bill this project wrote
 * down: four places listed work items and each asked a FIXED question — a board
 * column, one person's work, one team's work, one report. Nowhere could
 * somebody ask a question of their own, which is why `filter[cf_<key>]` shipped
 * with no caller and why the filter grammar docs/05 §4 publishes had never been
 * exercised by the product that publishes it.
 *
 * **The URL is the state** (ADR 0012). Every filter is a navigation, so a view
 * reloads, links, and goes backwards — and the server renders it complete.
 *
 * A 422 is SHOWN rather than swallowed. Since ADR 0039 a filter this endpoint
 * does not answer is a refusal that names the key, and a screen that caught it
 * and rendered "no results" would turn the one honest error message in this
 * grammar back into the silence it was built to replace.
 */
export default async function BrowseWorkPage({
  searchParams,
}: {
  searchParams: Promise<BrowseParams>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  // 404, not 403, like every other refusal in this app: whether the thing
  // exists is not this page's to disclose. Checked here as well as by the API
  // because the nav hides this entry without the permission, and a URL is
  // typed, pasted, bookmarked and followed from an old message.
  if (!me.permissions.includes("work_item.view")) notFound();

  const canAssign = me.permissions.includes("work_item.assign");
  const canUpdate = me.permissions.includes("work_item.update");

  const [projects, customFields, priorities, people] = await Promise.all([
    api<Array<{ id: string; key: string; name: string }>>("/projects?limit=200")
      .then((r) => r.data)
      .catch(() => []),
    // The same blank list the create form reads, and for the same reason: it is
    // guarded by `work_item.create`, not by `custom_field.manage`. Only LIVE
    // fields, because filtering by a retired one is a question about history
    // that this screen does not yet offer.
    api<CustomFieldAnswer[]>("/work-items/fields")
      .then((r) => r.data)
      .catch(() => [] as CustomFieldAnswer[]),
    // The priority scale, served rather than copied (ADR 0047).
    api<WorkVocabulary>("/work-items/vocabulary")
      .then((r) => r.data.priorities)
      .catch(() => [] as string[]),
    // Only for somebody who can assign: the bulk bar's picker, and nothing
    // else on this page, reads it.
    canAssign
      ? api<Array<{ id: string; name: string | null }>>("/people?limit=200")
          .then((r) => r.data)
          .catch(() => [] as Array<{ id: string; name: string | null }>)
      : Promise.resolve([] as Array<{ id: string; name: string | null }>),
  ]);

  let items: WorkItem[] = [];
  let nextCursor: string | null = null;
  let refusal: string | null = null;

  try {
    const page = await api<WorkItem[]>(`/work-items?${apiQuery(params, PER_PAGE)}`);

    items = page.data;

    const pagination = page.meta?.pagination;
    nextCursor =
      typeof pagination === "object" && pagination !== null && "next_cursor" in pagination
        ? ((pagination as { next_cursor: string | null }).next_cursor ?? null)
        : null;
  } catch (error) {
    refusal = describeApiError(error).error;
  }

  const active = activeCount(params);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Work"
        description={
          active === 0
            ? "Everything you can see. Narrow it with the filters."
            : `${active} ${active === 1 ? "filter" : "filters"} on.`
        }
        action={
          me.permissions.includes("work_item.create") ? (
            <ButtonLink href="/work/new" variant="primary" size="sm">
              New work item
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        <Panel id="filters" title="Filters" description="Every choice here is part of the address — bookmark it, or send it to somebody.">
          <BrowseFilters
            projects={projects.map((project) => ({
              id: project.id,
              label: `${project.key} · ${project.name}`,
            }))}
            priorities={priorities}
            customFields={customFields}
            active={active}
          />
        </Panel>

        {refusal !== null ? (
          // The API's own sentence, not a rewritten one. It names the filter it
          // refused, which is the whole value of the refusal.
          <Panel id="refused" title="That filter was refused" tone="danger">
            <p className="max-w-prose text-body-sm text-n-900">{refusal}</p>
            <p className="mt-2 text-caption text-n-500">
              <Link href="/work" className="underline">
                Start again with no filters
              </Link>
            </p>
          </Panel>
        ) : items.length === 0 ? (
          <EmptyState
            title={active === 0 ? "No work you can see" : "Nothing matches those filters"}
            description={
              active === 0
                ? "Work you have access to will appear here as it is created."
                : "Every filter narrows the list. Clear one and try again."
            }
          />
        ) : (
          <Panel
            id="results"
            title="Results"
            description={`${items.length} on this page.`}
            bleed
            footer={
              nextCursor === null ? undefined : (
                <ButtonLink
                  href={`/work?${new URLSearchParams({ ...cleaned(params), cursor: nextCursor })}`}
                  size="sm"
                  variant="secondary"
                >
                  Next page
                </ButtonLink>
              )
            }
          >
            <BulkSelectList
              // Remounted per page of results (filters and cursor), so a
              // selection never outlives the page it was made on — but NOT
              // when the same page refreshes after a bulk change, which may
              // reorder its rows and must keep the refusals on screen.
              key={apiQuery(params, PER_PAGE)}
              items={items}
              timeZone={me.user.timezone}
              people={people.map((person) => ({ id: person.id, label: person.name ?? "Unnamed" }))}
              canAssign={canAssign}
              canUpdate={canUpdate}
            />
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

/**
 * The current filters, without the cursor, as plain strings.
 *
 * `URLSearchParams` takes `Record<string, string>`, and an `undefined` in there
 * becomes the literal "undefined" in the query — which since ADR 0039 is a 422
 * rather than a value silently ignored, so it would be visible. Dropped here
 * anyway: a link that only works because the server refuses it is not a link.
 */
function cleaned(params: BrowseParams): Record<string, string> {
  const out: Record<string, string> = {};

  for (const [key, value] of Object.entries(params)) {
    if (key !== "cursor" && value !== undefined && value !== "") out[key] = value;
  }

  return out;
}
