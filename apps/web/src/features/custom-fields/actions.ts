"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";
import type { FieldScope, FieldType } from "./types";

/**
 * Writing a field definition (ADR 0038).
 *
 * The refusals are the product here: a key already taken, a key or type
 * somebody tried to change after the fact, a select with no options. Each
 * arrives as a sentence from the API and `describeApiError` is what keeps it
 * one — a screen that prints `error.details` instead shows the person
 * `key_taken client`, which this product has already shipped twice.
 */
export type FieldResult = { error: string | null };

function refresh(): void {
  revalidatePath("/settings/fields");
}

export async function declareField(
  scope: FieldScope,
  input: { key: string; label: string; type: FieldType; required: boolean; options: string[] },
): Promise<FieldResult> {
  try {
    await api(`/custom-fields/${scope}`, {
      method: "POST",
      body: {
        key: input.key,
        label: input.label,
        type: input.type,
        required: input.required,
        // Sent only for the type that has them. An empty `options` on a text
        // field would be a setting nothing honours, stored forever.
        ...(input.type === "select" ? { config: { options: input.options } } : {}),
      },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function saveField(
  scope: FieldScope,
  id: string,
  input: { label?: string; required?: boolean; options?: string[] },
): Promise<FieldResult> {
  try {
    await api(`/custom-fields/${scope}/${id}`, {
      method: "PATCH",
      body: {
        ...(input.label === undefined ? {} : { label: input.label }),
        ...(input.required === undefined ? {} : { required: input.required }),
        ...(input.options === undefined ? {} : { config: { options: input.options } }),
      },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

/** Retire or bring back. Not a delete, and the control does not say delete. */
export async function setFieldLive(
  scope: FieldScope,
  id: string,
  live: boolean,
): Promise<FieldResult> {
  try {
    await api(`/custom-fields/${scope}/${id}/live`, { method: "POST", body: { live } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function deleteField(scope: FieldScope, id: string): Promise<FieldResult> {
  try {
    await api(`/custom-fields/${scope}/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function reorderFields(scope: FieldScope, order: string[]): Promise<FieldResult> {
  try {
    await api(`/custom-fields/${scope}/order`, { method: "POST", body: { order } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}
