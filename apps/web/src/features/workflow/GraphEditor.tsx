"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { StatusChip, type StateCategory } from "@/components/ui/StatusChip";
import {
  addState,
  addTransition,
  removeState,
  removeTransition,
  renameState,
} from "./actions";
import type { Workflow, Vocabulary } from "./types";

/**
 * Editing the graph work moves through (ADR 0015).
 *
 * The edits here are the dull ones people ask for daily — rename a status, add
 * one, draw a move, remove a move nobody uses. The destructive ones are offered
 * and REFUSED by the API, and the refusal is printed as it arrives: "12 work
 * items are in this state. Move them first." is more useful than any missing
 * button, and a control that vanishes leaves somebody guessing why.
 *
 * Two things this form does not offer, because their absence costs nothing
 * today: reordering states, and guards on a new move. Both are in the ADR.
 */
export function GraphEditor({
  workflow,
  vocabulary,
}: {
  workflow: Workflow;
  vocabulary: Vocabulary;
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const run = (action: () => Promise<{ error: string | null }>) =>
    startAction(async () => {
      const result = await action();

      setError(result.error);
    });

  const byId = new Map(workflow.states.map((state) => [state.id, state]));

  return (
    <div className="space-y-6">
      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <section aria-labelledby="states-heading" className="space-y-3">
        <h2 id="states-heading" className="text-h2 font-semibold text-n-900">
          Statuses
        </h2>

        <ul className="divide-y divide-n-100 border-y border-n-100">
          {workflow.states.map((state) => (
            <li key={state.id} className="py-3">
              <StateRow
                state={state}
                busy={busy}
                onRename={(label) => run(() => renameState(workflow.id, state.id, label))}
                onRemove={() => run(() => removeState(workflow.id, state.id))}
              />
            </li>
          ))}
        </ul>

        <AddState vocabulary={vocabulary} busy={busy} onAdd={(input) => run(() => addState(workflow.id, input))} />
      </section>

      <section aria-labelledby="moves-heading" className="space-y-3">
        <h2 id="moves-heading" className="text-h2 font-semibold text-n-900">
          Moves
        </h2>

        <ul className="divide-y divide-n-100 border-y border-n-100">
          {workflow.transitions.map((transition) => (
            <li
              key={transition.id}
              className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-body-sm"
            >
              <span className="min-w-0 flex-1">
                <span className="text-n-900">{transition.label}</span>{" "}
                <span className="text-n-500">
                  {transition.from_state_id
                    ? (byId.get(transition.from_state_id)?.label ?? "—")
                    : "from any state"}{" "}
                  → {byId.get(transition.to_state_id)?.label ?? transition.to_state_id}
                </span>
                {transition.requires_comment && (
                  <span className="text-caption text-n-500"> · asks for a reason</span>
                )}
                {transition.is_guarded && (
                  <span className="text-caption text-n-500"> · not open to everyone</span>
                )}
              </span>

              <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() => run(() => removeTransition(workflow.id, transition.id))}
              >
                Remove
              </Button>
            </li>
          ))}
        </ul>

        <AddTransition
          workflow={workflow}
          busy={busy}
          onAdd={(input) => run(() => addTransition(workflow.id, input))}
        />
      </section>
    </div>
  );
}

function StateRow({
  state,
  busy,
  onRename,
  onRemove,
}: {
  state: Workflow["states"][number];
  busy: boolean;
  onRename: (label: string) => void;
  onRemove: () => void;
}) {
  const [label, setLabel] = useState(state.label);

  const renamed = label.trim() !== "" && label !== state.label;

  return (
    <div className="flex flex-wrap items-center gap-3">
      <span className="w-44 shrink-0">
        <StatusChip category={state.category as StateCategory} label={state.category} />
        <span className="mt-0.5 block font-mono text-micro text-n-500">{state.key}</span>
      </span>

      <input
        aria-label={`Name for ${state.key}`}
        className={`${INPUT} w-auto flex-1`}
        value={label}
        maxLength={60}
        onChange={(event) => setLabel(event.target.value)}
      />

      {/* The label is the customer's word and nothing in the product reads it,
          which is why renaming is free while the key and the category are
          refused (ADR 0015). */}
      <Button variant="secondary" size="sm" disabled={busy || !renamed} onClick={() => onRename(label)}>
        Rename
      </Button>

      <Button variant="ghost" size="sm" disabled={busy} onClick={onRemove}>
        Remove
      </Button>
    </div>
  );
}

function AddState({
  vocabulary,
  busy,
  onAdd,
}: {
  vocabulary: Vocabulary;
  busy: boolean;
  onAdd: (input: { key: string; label: string; category: string }) => void;
}) {
  const [key, setKey] = useState("");
  const [label, setLabel] = useState("");
  const [category, setCategory] = useState(vocabulary.state_categories[0] ?? "todo");

  return (
    <form
      className="flex flex-wrap items-end gap-3 rounded-lg border border-n-300 p-3"
      onSubmit={(event) => {
        event.preventDefault();
        onAdd({ key, label, category });
        setKey("");
        setLabel("");
      }}
    >
      <Field id="state-label" label="Name" hint="What people will see.">
        <input
          id="state-label"
          className={INPUT}
          value={label}
          maxLength={60}
          required
          onChange={(event) => setLabel(event.target.value)}
        />
      </Field>

      <Field id="state-key" label="Key" hint="What rules match on. It cannot be changed later.">
        <input
          id="state-key"
          className={INPUT}
          value={key}
          maxLength={40}
          required
          pattern="[a-z][a-z0-9_]*"
          onChange={(event) => setKey(event.target.value)}
        />
      </Field>

      <Field id="state-category" label="Counts as" hint="What every report and board reasons about.">
        <select
          id="state-category"
          className={INPUT}
          value={category}
          onChange={(event) => setCategory(event.target.value)}
        >
          {vocabulary.state_categories.map((value) => (
            <option key={value} value={value}>
              {value.replace("_", " ")}
            </option>
          ))}
        </select>
      </Field>

      <Button type="submit" variant="secondary" size="sm" disabled={busy}>
        Add status
      </Button>
    </form>
  );
}

function AddTransition({
  workflow,
  busy,
  onAdd,
}: {
  workflow: Workflow;
  busy: boolean;
  onAdd: (input: {
    from_state_id: string | null;
    to_state_id: string;
    label: string;
    requires_comment: boolean;
  }) => void;
}) {
  const [from, setFrom] = useState<string>("");
  const [to, setTo] = useState(workflow.states[0]?.id ?? "");
  const [label, setLabel] = useState("");
  const [requiresComment, setRequiresComment] = useState(false);

  return (
    <form
      className="flex flex-wrap items-end gap-3 rounded-lg border border-n-300 p-3"
      onSubmit={(event) => {
        event.preventDefault();
        // "" is the from-anywhere option, and it is sent as an explicit null:
        // the difference between "not sent" and "deliberately from anywhere"
        // is the whole meaning of that row.
        onAdd({
          from_state_id: from === "" ? null : from,
          to_state_id: to,
          label,
          requires_comment: requiresComment,
        });
        setLabel("");
      }}
    >
      <Field id="move-label" label="Name" hint="Named for what it does, not for the state it lands in.">
        <input
          id="move-label"
          className={INPUT}
          value={label}
          maxLength={60}
          required
          onChange={(event) => setLabel(event.target.value)}
        />
      </Field>

      <Field id="move-from" label="From">
        <select
          id="move-from"
          className={INPUT}
          value={from}
          onChange={(event) => setFrom(event.target.value)}
        >
          <option value="">any state</option>
          {workflow.states.map((state) => (
            <option key={state.id} value={state.id}>
              {state.label}
            </option>
          ))}
        </select>
      </Field>

      <Field id="move-to" label="To">
        <select
          id="move-to"
          className={INPUT}
          value={to}
          onChange={(event) => setTo(event.target.value)}
        >
          {workflow.states.map((state) => (
            <option key={state.id} value={state.id}>
              {state.label}
            </option>
          ))}
        </select>
      </Field>

      <label className="flex items-center gap-1.5 pb-1.5 text-body-sm">
        <input
          type="checkbox"
          checked={requiresComment}
          onChange={(event) => setRequiresComment(event.target.checked)}
        />
        asks for a reason
      </label>

      <Button type="submit" variant="secondary" size="sm" disabled={busy || to === ""}>
        Add move
      </Button>
    </form>
  );
}
