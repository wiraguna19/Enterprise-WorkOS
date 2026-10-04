import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { ApiTokenPanel, type ApiTokenRow } from "@/features/auth/ApiTokenPanel";
import type { TokenAccess } from "@/features/auth/api-token-actions";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDateTime } from "@/lib/format";

/**
 * API tokens (docs/10 Phase 7, ADR 0049).
 *
 * No permission gates the PAGE, like Settings → Signed in: it is an account
 * looking at its own credentials, and somebody who lost the permission to make
 * tokens must still be able to see and revoke the ones they made. Only the
 * form is gated, and it says why when it is absent.
 *
 * The address in the example is the one this server talks to. Where the API
 * is reachable from outside is a deployment fact; `PUBLIC_API_URL` names it
 * when the two differ.
 */
type Payload = {
  id: string;
  name: string;
  access: TokenAccess;
  created_at: string;
  last_used_at: string | null;
  expires_at: string;
};

export default async function ApiTokensPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const { data } = await api<Payload[]>("/me/api-tokens");

  // Formatted here, in the viewer's own zone, and handed over as strings.
  const tokens: ApiTokenRow[] = data.map((token) => ({
    id: token.id,
    name: token.name,
    access: token.access,
    created: formatDateTime(token.created_at, me.user.timezone, locale),
    lastUsed:
      token.last_used_at === null
        ? t("tok.never")
        : formatDateTime(token.last_used_at, me.user.timezone, locale),
    expires: formatDateTime(token.expires_at, me.user.timezone, locale),
  }));

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.apiTokens.label")}
        description={t("settings.apiTokens.description")}
      />

      <PageBody>
        <ApiTokenPanel
          tokens={tokens}
          mayCreate={me.permissions.includes("api_token.create")}
          apiBase={process.env.PUBLIC_API_URL ?? process.env.API_URL ?? "http://localhost:8000/api/v1"}
        />
      </PageBody>
    </div>
  );
}
