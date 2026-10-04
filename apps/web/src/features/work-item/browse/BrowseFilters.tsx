"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { priorityName } from "@/i18n/labels";
import { useT } from "@/i18n/I18nProvider";
import { CATEGORIES, SORTS } from "./query";

/**
 * The filter bar. Every control writes to the URL and nothing else (ADR 0012).
 *
 * There is no client data layer here and no local state holding a pending
 * filter: a change navigates, the server component refetches, and the result is
 * a page somebody can bookmark, reload, or send to a colleague. A filter bar
 * that keeps its state in React is a filter bar whose URL lies about what is on
 * screen.
 *
 * **Changing a filter clears the cursor.** Page 3 of one question is not page 3
 * of another; keeping it is how a list comes back empty for no visible reason.
 */
export function BrowseFilters({
  projects,
  priorities,
  customFields,
  active,
}: {
  projects: Array<{ id: string; label: string }>;
  /** Served by `GET /work-items/vocabulary` (ADR 0047), never written out here. */
  priorities: string[];
  customFields: CustomFieldAnswer[];
  active: number;
}) {
  const t = useT();
  const router = useRouter();
  const params = useSearchParams();
  const [navigating, start] = useTransition();

  function set(key: string, value: string): void {
    const next = new URLSearchParams(params.toString());

    if (value === "") {
      next.delete(key);
    } else {
      next.set(key, value);
    }

    // Page 3 of one question is not page 3 of another: keeping the cursor
    // across a filter change is how a list comes back empty for no visible
    // reason.
    next.delete("cursor");

    start(() => router.replace(next.toString() === "" ? "/work" : `/work?${next}`));
  }

  const value = (key: string): string => params.get(key) ?? "";

  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field id="f-category" label={t("status.status")}>
          <select
            id="f-category"
            value={value("category")}
            onChange={(event) => set("category", event.target.value)}
            className={INPUT}
          >
            <option value="">{t("browse.any")}</option>
            {CATEGORIES.map((category) => (
              <option key={category} value={category}>
                {t(`category.${category}`)}
              </option>
            ))}
          </select>
        </Field>

        <Field id="f-priority" label={t("form.priority")}>
          <select
            id="f-priority"
            value={value("priority")}
            onChange={(event) => set("priority", event.target.value)}
            className={INPUT}
          >
            <option value="">{t("browse.any")}</option>
            {priorities.map((priority) => (
              <option key={priority} value={priority}>
                {priorityName(priority, t)}
              </option>
            ))}
          </select>
        </Field>

        <Field id="f-project" label={t("wnew.project")}>
          <select
            id="f-project"
            value={value("project")}
            onChange={(event) => set("project", event.target.value)}
            className={INPUT}
          >
            <option value="">{t("browse.any")}</option>
            {projects.map((project) => (
              <option key={project.id} value={project.id}>
                {project.label}
              </option>
            ))}
          </select>
        </Field>

        <Field id="f-sort" label={t("browse.order")}>
          <select
            id="f-sort"
            value={value("sort")}
            onChange={(event) => set("sort", event.target.value)}
            className={INPUT}
          >
            {SORTS.map((sort) => (
              <option key={sort.value} value={sort.value}>
                {t(sort.label)}
              </option>
            ))}
          </select>
        </Field>
      </div>

      {customFields.length > 0 && (
        // The organization's own fields, filtered by exact value. This screen
        // is the reason `filter[cf_<key>]` — published in docs/05 §4 since
        // Phase 2 — has a caller at all.
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {customFields.map((field) => (
            <Field
              key={field.key}
              id={`f-cf-${field.key}`}
              label={field.label}
              hint={t("browse.exact", { param: `cf_${field.key}` })}
            >
              {field.type === "select" ? (
                <select
                  id={`f-cf-${field.key}`}
                  value={value(`cf_${field.key}`)}
                  onChange={(event) => set(`cf_${field.key}`, event.target.value)}
                  className={INPUT}
                >
                  <option value="">{t("browse.any")}</option>
                  {field.options.map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  id={`f-cf-${field.key}`}
                  type={field.type === "date" ? "date" : "text"}
                  defaultValue={value(`cf_${field.key}`)}
                  // On blur and on Enter, not on every keystroke: each change
                  // here is a navigation and a round trip, and filtering as
                  // somebody types would fire one per letter.
                  onBlur={(event) => set(`cf_${field.key}`, event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") set(`cf_${field.key}`, event.currentTarget.value);
                  }}
                  className={INPUT}
                />
              )}
            </Field>
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Toggle
          label={t("browse.overdueOnly")}
          on={value("late") === "1"}
          onChange={(on) => set("late", on ? "1" : "")}
        />
        <Toggle
          label={t("browse.unassignedOnly")}
          on={value("unassigned") === "1"}
          onChange={(on) => set("unassigned", on ? "1" : "")}
        />
        <Toggle
          label={t("browse.includeArchived")}
          on={value("archived") === "include" || value("archived") === "only"}
          // Archived work is closed work out of the working set (ADR 0054).
          // Off by default, like the board; `only` arrives from a board's
          // "see them" link and is kept until this is switched off.
          onChange={(on) => set("archived", on ? "include" : "")}
        />
        <Toggle
          label={t("browse.mine")}
          on={value("assignee") === "me"}
          // `me` is resolved by the server (docs/05 §4), so this screen never
          // has to know the reader's own membership id.
          onChange={(on) => set("assignee", on ? "me" : "")}
        />

        {active > 0 && (
          <Button
            size="sm"
            variant="ghost"
            disabled={navigating}
            onClick={() => start(() => router.replace("/work"))}
          >
            {t.plural("browse.clear", active)}
          </Button>
        )}

        {/* Announced, not just shown: the list below changes under the reader
            and a spinner alone tells a screen reader nothing. */}
        <span role="status" aria-live="polite" className="text-caption text-n-500">
          {navigating ? t("browse.filtering") : ""}
        </span>
      </div>
    </div>
  );
}

/** A filter that is on or off, as a real checkbox rather than a styled button. */
function Toggle({
  label,
  on,
  onChange,
}: {
  label: string;
  on: boolean;
  onChange: (on: boolean) => void;
}) {
  return (
    <label className="inline-flex items-center gap-1.5 rounded-md border border-n-300 bg-n-0 px-2 py-1 text-body-sm text-n-700">
      <input
        type="checkbox"
        checked={on}
        onChange={(event) => onChange(event.target.checked)}
        className="size-3.5 rounded-sm border-n-300"
      />
      {label}
    </label>
  );
}
