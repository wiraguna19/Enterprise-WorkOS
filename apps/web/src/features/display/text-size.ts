/**
 * A person's reading size (docs/09 §2).
 *
 * Three steps, each one the interface is laid out and tested at. The step
 * lives on the account (`users.text_size`) so it follows the person; a cookie
 * keeps this browser's copy, so the root layout can put it on `<html>` before
 * the first paint instead of the page jumping a size once JavaScript runs.
 */
export const TEXT_SIZES = ["normal", "large", "larger"] as const;

export type TextSize = (typeof TEXT_SIZES)[number];

export const TEXT_SIZE_COOKIE = "wos_text_size";

export function isTextSize(value: unknown): value is TextSize {
  return typeof value === "string" && (TEXT_SIZES as readonly string[]).includes(value);
}

export function asTextSize(value: unknown): TextSize {
  return isTextSize(value) ? value : "normal";
}
