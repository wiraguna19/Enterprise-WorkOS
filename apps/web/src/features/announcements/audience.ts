import type { Translator } from "@/i18n/translate";
import type { Audience } from "./types";

/** Who an announcement was for, in the reader's language. */
export function audienceName(audience: Audience, t: Translator): string {
  if (audience.type === "organization") return t("ann.audience.organization");

  const name = audience.name ?? t("ann.audience.removed");

  return audience.type === "team"
    ? t("ann.audience.team", { name })
    : t("ann.audience.department", { name });
}
