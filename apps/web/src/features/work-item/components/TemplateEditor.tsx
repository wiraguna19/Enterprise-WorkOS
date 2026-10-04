"use client";

import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, Td, THead, Th, Tr } from "@/components/ui/DataTable";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { CustomFieldInputs } from "@/features/custom-fields/CustomFieldInputs";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { priorityName } from "@/i18n/labels";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { createTemplate, deleteTemplate, saveTemplate } from "../template-actions";
import {
  applicableAnswers,
  describeFields,
  humanize,
  type TemplateFields,
  type WorkItemTemplate,
  type WorkVocabulary,
} from "../templates";

/**
 * Writing the starting points people pick from when they create work (ADR 0047).
 *
 * Two things this screen is careful about, both because a template is read
 * somewhere else — on the create form — by somebody who never saw this page:
 *
 *   1. **It offers only what the API accepts.** No assignee, no project, no
 *      calendar date: the API refuses each by name, so the form does not render
 *      a control whose save would be refused. A deadline is "due in N days",
 *      counted from the day the form is opened.
 *   2. **It says what no longer applies.** A custom field retired after the
 *      template was written is still in the template, and is skipped when the
 *      template is used. The row says so, and so does the editor — and it says
 *      that saving drops it, because it does.
 */
export function TemplateEditor({
  templates,
  vocabulary,
  customFields,
  project,
}: {
  /** Only the ones this editor is for: the organization's, or one project's. */
  templates: WorkItemTemplate[];
  /**
   * Set when this is one project's editor (ADR 0058): its templates are offered
   * on that project's forms only, and written by its owner and managers.
   */
  project?: { key: string; name: string };
  vocabulary: WorkVocabulary;
  /** Live fields only, blank — the same list the create form gets. */
  customFields: CustomFieldAnswer[];
}) {
  const t = useT();
  const locale = useLocale();
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [saving, start] = useTransition();

  function run(action: () => Promise<{ error: string | null }>, after?: () => void): void {
    start(async () => {
      const result = await action();
      setError(result.error);

      if (result.error === null) {
        setEditing(null);
        after?.();
      }
    });
  }

  return (
    <div className="space-y-4">
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <Panel
        id="templates"
        title={t("settings.templates.label")}
        description={
          project !== undefined
            ? templates.length === 0
              ? t("tpl.project.empty", { project: project.name })
              : t("tpl.project.count", { count: templates.length, project: project.name })
            : templates.length === 0
              ? t("tpl.org.empty")
              : t("tpl.org.count", { count: templates.length })
        }
        bleed
      >
        {templates.length > 0 && (
          <DataTable caption={t("tpl.caption")}>
            <THead>
              <Tr>
                <Th>{t("projects.col.name")}</Th>
                <Th>{t("tpl.col.fills")}</Th>
                <Th align="right">{t("members.col.actions")}</Th>
              </Tr>
            </THead>
            <TBody>
              {templates.map((template) => {
                const stale = applicableAnswers(template.fields, customFields).skipped;

                return (
                  <Tr key={template.id}>
                    <Td>
                      <span className="font-medium">{template.name}</span>
                      {template.purpose && (
                        <span className="block max-w-prose text-caption text-n-500">
                          {template.purpose}
                        </span>
                      )}
                    </Td>
                    <Td muted>
                      {describeFields(template.fields, locale)}
                      {stale.length > 0 && (
                        <span className="mt-1 block">
                          <Badge tone="neutral" icon="minus">
                            {t("tpl.skips", { fields: stale.join(", ") })}
                          </Badge>
                        </span>
                      )}
                    </Td>
                    <Td align="right">
                      <span className="inline-flex flex-wrap justify-end gap-1">
                        <Button
                          size="sm"
                          variant="secondary"
                          disabled={saving}
                          onClick={() => setEditing(editing === template.id ? null : template.id)}
                        >
                          {editing === template.id ? t("tpl.close") : t("common.edit")}
                        </Button>
                        <DeleteTemplate
                          name={template.name}
                          disabled={saving}
                          onConfirm={() => run(() => deleteTemplate(template.id, project === undefined ? {} : { projectKey: project.key }))}
                        />
                      </span>
                    </Td>
                  </Tr>
                );
              })}
            </TBody>
          </DataTable>
        )}
      </Panel>

      {templates
        .filter((template) => template.id === editing)
        .map((template) => (
          <TemplateForm
            key={template.id}
            id={`edit-${template.id}`}
            title={t("ms.edit", { name: template.name })}
            submitLabel={t("common.save")}
            initial={template}
            vocabulary={vocabulary}
            customFields={customFields}
            saving={saving}
            onSubmit={(input) => run(() => saveTemplate(template.id, input, project === undefined ? {} : { projectKey: project.key }))}
          />
        ))}

      <TemplateForm
        // Remounted after each successful create, which is how the form
        // empties itself without a reset function for every field.
        key={`new-${templates.length}`}
        id="new-template"
        title={project === undefined ? t("tpl.write") : t("tpl.writeFor", { project: project.name })}
        submitLabel={t("tpl.saveTemplate")}
        vocabulary={vocabulary}
        customFields={customFields}
        saving={saving}
        onSubmit={(input) => run(() => createTemplate(input, project === undefined ? {} : { projectKey: project.key }))}
      />
    </div>
  );
}

/**
 * Deleting asks first, in place — the same two-click shape the fields screen
 * uses, and for the same reasons a browser `confirm()` is not used.
 *
 * The button says what is NOT lost: work already created from the template is
 * untouched, because nothing records where an item started.
 */
function DeleteTemplate({
  name,
  disabled,
  onConfirm,
}: {
  name: string;
  disabled: boolean;
  onConfirm: () => void;
}) {
  const t = useT();
  const [armed, setArmed] = useState(false);

  if (!armed) {
    return (
      <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setArmed(true)}>
        {t("tpl.delete")}
      </Button>
    );
  }

  return (
    <span className="inline-flex items-center gap-1">
      <Button
        size="sm"
        variant="danger"
        disabled={disabled}
        onClick={() => {
          setArmed(false);
          onConfirm();
        }}
      >
        {t("tpl.deleteConfirm", { name })}
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={() => setArmed(false)}>
        {t("common.cancel")}
      </Button>
    </span>
  );
}

function TemplateForm({
  id,
  title,
  submitLabel,
  initial,
  vocabulary,
  customFields,
  saving,
  onSubmit,
}: {
  id: string;
  title: string;
  submitLabel: string;
  initial?: WorkItemTemplate;
  vocabulary: WorkVocabulary;
  customFields: CustomFieldAnswer[];
  saving: boolean;
  onSubmit: (input: { name: string; purpose: string; fields: TemplateFields }) => void;
}) {
  const t = useT();
  const locale = useLocale();
  const start = initial?.fields ?? {};

  const [name, setName] = useState(initial?.name ?? "");
  const [purpose, setPurpose] = useState(initial?.purpose ?? "");
  const [itemTitle, setItemTitle] = useState(start.title ?? "");
  const [description, setDescription] = useState(start.description ?? "");
  const [type, setType] = useState(start.type ?? "");
  const [priority, setPriority] = useState(start.priority ?? "");
  const [estimate, setEstimate] = useState(
    start.estimate_hours === undefined ? "" : String(start.estimate_hours),
  );
  const [dueIn, setDueIn] = useState(
    start.due_in_days === undefined ? "" : String(start.due_in_days),
  );
  const [custom, setCustom] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      customFields.map((field) => [field.key, start.custom_fields?.[field.key] ?? ""]),
    ),
  );

  const stale = initial === undefined ? [] : applicableAnswers(initial.fields, customFields).skipped;

  const nameId = useId();
  const purposeId = useId();
  const titleId = useId();
  const descriptionId = useId();
  const typeId = useId();
  const priorityId = useId();
  const estimateId = useId();
  const dueId = useId();

  // Blanks are not sent. The API would drop them anyway, but an absent key and
  // a blank one are different things to read in a request log.
  const fields: TemplateFields = {
    ...(itemTitle.trim() === "" ? {} : { title: itemTitle }),
    ...(description.trim() === "" ? {} : { description }),
    ...(type === "" ? {} : { type }),
    ...(priority === "" ? {} : { priority }),
    ...(estimate === "" ? {} : { estimate_hours: Number(estimate) }),
    ...(dueIn === "" ? {} : { due_in_days: Number(dueIn) }),
  };

  const answered = Object.fromEntries(
    Object.entries(custom).filter(([, value]) => value !== ""),
  );

  if (Object.keys(answered).length > 0) fields.custom_fields = answered;

  // Why the button is off, in the words of the thing that is missing. A
  // disabled control with no reason is a dead end.
  const blocker =
    name.trim() === ""
      ? t("tpl.needName")
      : Object.keys(fields).length === 0
        ? t("tpl.needField")
        : null;

  return (
    <Panel
      id={id}
      title={title}
      description={t("tpl.formDesc")}
      footer={
        <div className="flex flex-wrap items-center gap-3">
          <Button
            variant="primary"
            size="sm"
            disabled={saving || blocker !== null}
            onClick={() => onSubmit({ name: name.trim(), purpose, fields })}
          >
            {saving ? t("common.saving") : submitLabel}
          </Button>

          {blocker !== null && (
            <p role="status" className="text-caption text-n-500">
              {blocker}
            </p>
          )}
        </div>
      }
    >
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field id={nameId} label={t("projects.col.name")} hint={t("tpl.nameHint")}>
            <input
              id={nameId}
              value={name}
              maxLength={80}
              onChange={(event) => setName(event.target.value)}
              placeholder={t("tpl.namePlaceholder")}
              className={INPUT}
            />
          </Field>

          <Field id={purposeId} label={t("tpl.purpose")} hint={t("tpl.purposeHint")}>
            <input
              id={purposeId}
              value={purpose}
              maxLength={500}
              onChange={(event) => setPurpose(event.target.value)}
              className={INPUT}
            />
          </Field>
        </div>

        <div className="space-y-3 border-t border-n-100 pt-4">
          <h3 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
            {t("tpl.col.fills")}
          </h3>

          <Field id={titleId} label={t("tpl.titleLabel")} hint={t("tpl.titleHint")}>
            <input
              id={titleId}
              value={itemTitle}
              maxLength={500}
              onChange={(event) => setItemTitle(event.target.value)}
              className={INPUT}
            />
          </Field>

          <Field id={descriptionId} label={t("wi.description")} hint={t("tpl.descHint")}>
            <textarea
              id={descriptionId}
              rows={4}
              value={description}
              maxLength={20000}
              onChange={(event) => setDescription(event.target.value)}
              className={`${INPUT} resize-y`}
            />
          </Field>

          <div className="grid gap-3 sm:grid-cols-2">
            <Field id={typeId} label={t("tpl.type")}>
              <select
                id={typeId}
                value={type}
                onChange={(event) => setType(event.target.value)}
                className={INPUT}
              >
                <option value="">{t("tpl.notSet")}</option>
                {vocabulary.types.map((value) => (
                  <option key={value} value={value}>
                    {humanize(value)}
                  </option>
                ))}
              </select>
            </Field>

            <Field id={priorityId} label={t("form.priority")}>
              <select
                id={priorityId}
                value={priority}
                onChange={(event) => setPriority(event.target.value)}
                className={INPUT}
              >
                <option value="">{t("tpl.notSet")}</option>
                {vocabulary.priorities.map((value) => (
                  <option key={value} value={value}>
                    {priorityName(value, t)}
                  </option>
                ))}
              </select>
            </Field>

            <Field id={estimateId} label={t("wi.field.estimate")} hint={t("tpl.estimateHint")}>
              <input
                id={estimateId}
                type="number"
                inputMode="decimal"
                step="0.25"
                min="0"
                max="9999"
                value={estimate}
                onChange={(event) => setEstimate(event.target.value)}
                className={INPUT}
              />
            </Field>

            <Field
              id={dueId}
              label={t("tpl.dueIn")}
              hint={t("tpl.dueInHint")}
            >
              <input
                id={dueId}
                type="number"
                inputMode="numeric"
                step="1"
                min="0"
                max="365"
                value={dueIn}
                onChange={(event) => setDueIn(event.target.value)}
                className={INPUT}
              />
            </Field>
          </div>
        </div>

        {customFields.length > 0 && (
          <div className="space-y-3 border-t border-n-100 pt-4">
            <h3 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
              {t("wi.customFields")}
            </h3>

            <CustomFieldInputs
              // Not required HERE, whatever they are on the form: a template
              // that leaves a required field blank leaves it for the person
              // creating the work, and the create form asks them.
              fields={customFields.map((field) => ({ ...field, required: false }))}
              values={custom}
              idPrefix={`${id}-cf`}
              onChange={(key, value) => setCustom((current) => ({ ...current, [key]: value }))}
              locale={locale}
            />
          </div>
        )}

        {stale.length > 0 && (
          <p role="status" className="text-caption text-n-500">
            {t("tpl.stale", { fields: stale.join(", ") })}
          </p>
        )}
      </div>
    </Panel>
  );
}
