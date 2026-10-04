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

// What each type is for lives in the dictionaries now (`fld.typeHint.*`), so
// it reads in the declaring person's language (ADR 0060).

/**
 * One declared field as it appears ON a record, answer included (ADR 0038).
 *
 * Distinct from `CustomField` above, which is the administrator's view: this
 * one carries no id, no position and no filter key, because the form does not
 * need them and the record does not either. It carries `live`, which the
 * administrator's list expresses as a separate column — a retired field with an
 * answer still has to be PRINTED and must not be OFFERED.
 */
export type CustomFieldAnswer = {
  key: string;
  label: string;
  type: FieldType;
  options: string[];
  required: boolean;
  live: boolean;
  /** Always a string or null — a number leaves the API undamaged, as text. */
  value: string | null;
};
