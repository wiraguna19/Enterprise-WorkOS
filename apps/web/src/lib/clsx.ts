/**
 * Minimal class joiner. A dependency for this is not worth the install.
 */
export function clsx(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(" ");
}
