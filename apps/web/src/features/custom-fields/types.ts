/**
 * A field an organization declared for itself (ADR 0038).
 *
 * Mirrors what `CustomFieldController::present()` sends, field for field. This
 * contract is hand-written and has drifted before — `ApprovalResource` emitted
 * `reviewers` while this app said `approvers` for three phases, compiling the
 * whole time, because no component read it. Every field below is read by the
 * editor, which is the only thing that keeps a type honest.
 */
export type FieldScope = "work_item" | "project";

export type FieldType = "text" | "number" | "date" | "select";

export type CustomField = {
  id: string;
  scope: FieldScope;
  key: string;
  /** What a filter would call it — `cf_client`. Served, never assembled here. */
  filter_key: string;
  label: string;
  type: FieldType;
  /** Empty for every type but `select`. */
  options: string[];
  required: boolean;
  position: number;
  live: boolean;
  retired_at: string | null;
};

export type FieldVocabulary = {
  scopes: FieldScope[];
  types: FieldType[];
};

/** What each type is for, in the words the person declaring one would use. */
export const TYPE_DESCRIPTIONS: Record<FieldType, string> = {
  text: "A short line — a client name, a ticket reference.",
  number: "An amount or a quantity. Filters and sorts as a number.",
  date: "A calendar date, separate from the item's own dates.",
  select: "One of a list you write. The list is the point.",
};
