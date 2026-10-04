import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import type { TokenAccess } from "@/features/auth/api-token-actions";
import { ServiceAccountPanel, type ServiceAccountRow } from "@/features/auth/ServiceAccountPanel";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDateTime } from "@/lib/format";

type Account = {
  id: string;
  name: string;
  active: boolean;
  role: { key: string; name: string } | null;
  tokens: number;
};

type Token = {
  id: string;
  name: string;
  access: TokenAccess;
  last_used_at: string | null;
  expires_at: string;
};

/**
 * Settings → Service accounts (ADR 0059).
 *
 * 404 without `service_account.manage`, like every gated settings screen: the
 * API refuses every route here to anybody else, and a page that 403s on load
 * is a page that should not have been reachable.
 */
export default async function ServiceAccountsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("service_account.manage")) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const [{ data: accounts, meta }, roles] = await Promise.all([
    api<Account[]>("/service-accounts"),
    api<Array<{ key: string; name: string }>>("/roles")
      .then((r) => r.data)
      .catch(() => [] as Array<{ key: string; name: string }>),
  ]);

  // Which roles it may not hold, as the API says — not a copy of the rule.
  const refused = Array.isArray(meta?.refused_roles) ? (meta.refused_roles as string[]) : [];

  // One read per account for its tokens. There are few accounts, and the
  // list endpoint stays a list of accounts rather than growing a nested one.
  const rows: ServiceAccountRow[] = await Promise.all(
    accounts.map(async (account) => {
      const tokens =
        account.active && account.tokens > 0
          ? await api<Token[]>(`/service-accounts/${account.id}/tokens`)
              .then((r) => r.data)
              .catch(() => [] as Token[])
          : [];

      return {
        id: account.id,
        name: account.name,
        active: account.active,
        role: account.role,
        tokens: tokens.map((token) => ({
          id: token.id,
          name: token.name,
          access: token.access,
          lastUsed:
            token.last_used_at === null
              ? t("tok.never")
              : formatDateTime(token.last_used_at, me.user.timezone, locale),
          expires: formatDateTime(token.expires_at, me.user.timezone, locale),
        })),
      };
    }),
  );

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.serviceAccounts.label")}
        description={t("svc.description")}
      />

      <PageBody>
        <ServiceAccountPanel
          accounts={rows}
          roles={roles.filter((role) => !refused.includes(role.key))}
          apiBase={process.env.PUBLIC_API_URL ?? process.env.API_URL ?? "http://localhost:8000/api/v1"}
        />
      </PageBody>
    </div>
  );
}
