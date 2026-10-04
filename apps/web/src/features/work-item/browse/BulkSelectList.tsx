"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import type { MessageKey } from "@/i18n/messages/en";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { bulkUpdateWorkItems, type BulkChange, type BulkOutcome } from "../actions";
import { WorkItemRow } from "../components/WorkItemRow";
import type { WorkItem } from "../types";

type Person = { id: string; label: string };

/**
 * The browse list, with a way to act on several rows at once (docs/08 §8,
 * ADR 0055).
 *
 * **A checkbox BESIDE the row, not in it.** The row is a link to the item,
 * and a checkbox inside an anchor is invalid markup that some browsers resolve
 * by following the link on the tick. Beside it, the row still opens and the
 * box still only selects.
 *
 * **Selection is this page's rows.** "Select all" means all on this page —
 * the list is cursor-paginated, and "every item matching these filters" is a
 * different, bigger promise (a server-side selection, an item count you have
 * not seen) that this does not make.
 *
 * **What failed stays selected.** The API answers per item; the ones it
 * refused are named in the bar with their own sentence and are still ticked,
 * so the next step — fix the cause, press the same button — needs no
 * re-selecting. The ones that succeeded drop out of the selection.
 */
export function BulkSelectList({
  items,
  timeZone,
  people,
  canAssign,
  canUpdate,
}: {
  items: WorkItem[];
  timeZone: string;
  people: Person[];
  /** Coarse: the API asks the policy about every item anyway. */
  canAssign: boolean;
  canUpdate: boolean;
}) {
  const t = useT();
  const locale = useLocale();
  const [selected, setSelected] = useState<string[]>([]);
  const [assignee, setAssignee] = useState("");
  const [due, setDue] = useState("");
  const [refused, setRefused] = useState<BulkOutcome[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();

  const selectable = canAssign || canUpdate;
  const onPage = items.map((item) => item.reference);
  const allOnPage = onPage.length > 0 && onPage.every((reference) => selected.includes(reference));

  const toggle = (reference: string) =>
    setSelected((current) =>
      current.includes(reference) ? current.filter((r) => r !== reference) : [...current, reference],
    );

  const run = (change: BulkChange, doing: MessageKey) =>
    start(async () => {
      const result = await bulkUpdateWorkItems(selected, change);

      setError(result.error);

      if (result.error !== null || result.results === undefined) return;

      const failed = result.results.filter((outcome) => !outcome.ok);

      setRefused(failed);
      setSelected(failed.map((outcome) => outcome.reference));

      if ((result.succeeded ?? 0) > 0) {
        toast({
          message:
            t.plural("bulk.done", result.succeeded ?? 0, { doing: t(doing) })
            + (failed.length > 0 ? ` ${t("bulk.refusedTail", { count: failed.length })}` : ""),
        });
      }
    });

  if (!selectable) {
    return (
      <ul>
        {items.map((item) => (
          <li key={item.id}>
            <WorkItemRow item={item} timeZone={timeZone} locale={locale} />
          </li>
        ))}
      </ul>
    );
  }

  return (
    <>
      <div className="flex items-center gap-3 border-b border-n-100 px-3 py-1.5">
        <input
          id="bulk-select-page"
          type="checkbox"
          checked={allOnPage}
          onChange={() => setSelected(allOnPage ? [] : onPage)}
          className="size-4 accent-a-500"
        />
        <label htmlFor="bulk-select-page" className="text-caption text-n-500">
          {t("bulk.selectAll")}
        </label>
      </div>

      <ul>
        {items.map((item) => (
          <li key={item.id} className="flex items-center">
            <input
              type="checkbox"
              checked={selected.includes(item.reference)}
              onChange={() => toggle(item.reference)}
              aria-label={t("bulk.select", { reference: item.reference })}
              className="ml-3 size-4 shrink-0 accent-a-500"
            />
            <div className="min-w-0 flex-1">
              <WorkItemRow
                item={item}
                timeZone={timeZone}
                selected={selected.includes(item.reference)}
                locale={locale}
              />
            </div>
          </li>
        ))}
      </ul>

      {selected.length > 0 && (
        <>
          {/* Room for the bar, so it never sits over the last rows. */}
          <div aria-hidden className="h-48 md:h-32" />

          <div
            role="region"
            aria-label={t("bulk.region")}
            // Above the phone's tab bar (z-20), like every other overlay here.
            className="fixed inset-x-0 bottom-0 z-30 border-t border-n-200 bg-n-0/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-e2 backdrop-blur md:inset-x-auto md:bottom-4 md:left-1/2 md:w-[44rem] md:-translate-x-1/2 md:rounded-xl md:border"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-body-sm font-medium text-n-900" aria-live="polite">
                {t("bulk.selected", { count: selected.length })}
              </p>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setSelected([]);
                  setRefused([]);
                  setError(null);
                }}
              >
                {t("bulk.clear")}
              </Button>
            </div>

            <div className="mt-2 flex flex-col gap-2 md:flex-row md:items-end">
              {canAssign && (
                <div className="flex flex-1 items-end gap-2">
                  <label className="flex-1 text-caption text-n-700">
                    {t("bulk.assignTo")}
                    <select
                      value={assignee}
                      onChange={(event) => setAssignee(event.target.value)}
                      className={`${INPUT} mt-0.5`}
                    >
                      <option value="">{t("bulk.choosePerson")}</option>
                      {people.map((person) => (
                        <option key={person.id} value={person.id}>
                          {person.label}
                        </option>
                      ))}
                    </select>
                  </label>
                  <Button
                    variant="affirmative"
                    size="sm"
                    disabled={busy || assignee === ""}
                    onClick={() => run({ assignee_id: assignee }, "bulk.assigned")}
                  >
                    {t("assign.assign")}
                  </Button>
                </div>
              )}

              {canUpdate && (
                <div className="flex flex-1 items-end gap-2">
                  <label className="flex-1 text-caption text-n-700">
                    {t("bulk.dueDate")}
                    <input
                      type="date"
                      value={due}
                      onChange={(event) => setDue(event.target.value)}
                      className={`${INPUT} mt-0.5`}
                    />
                  </label>
                  <Button
                    variant="affirmative"
                    size="sm"
                    disabled={busy || due === ""}
                    // The end of the working day, as the create form sends it:
                    // a bare date would be midnight, and "due Tuesday" would be
                    // overdue from the first minute of Tuesday.
                    onClick={() => run({ due_at: `${due}T17:00:00` }, "bulk.dueSet")}
                  >
                    {t("bulk.setDue")}
                  </Button>
                </div>
              )}
            </div>

            {error !== null && (
              <p role="alert" className="mt-2 text-caption text-s-danger">
                {error}
              </p>
            )}

            {refused.length > 0 && (
              <div role="alert" className="mt-2 max-h-28 overflow-y-auto rounded-md border border-s-danger/30 bg-s-danger/5 px-3 py-2">
                <p className="text-caption font-medium text-s-danger">
                  {t("bulk.notChanged", { count: refused.length })}
                </p>
                <ul className="mt-1 space-y-0.5 text-caption text-n-700">
                  {refused.map((outcome) => (
                    <li key={outcome.reference}>
                      <span className="font-mono">{outcome.reference}</span> — {outcome.error?.message}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </>
      )}
    </>
  );
}
