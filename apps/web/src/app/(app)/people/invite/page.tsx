import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { InviteForm } from "@/features/people/InviteForm";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * Inviting somebody into the organization (ADR 0017).
 *
 * A page, not a dialog: it ends by handing over a link that has to be copied,
 * and a dialog is the worst place to put something somebody must not lose.
 */
export default async function InvitePersonPage() {
  const me = await requireUser();

  if (!me.permissions.includes("person.invite")) notFound();

  // Translated (ADR 0060).
  const t = translator(asLocale(me.user.locale));

  // The roles this organization has, not the four this file could have listed.
  const roles = me.permissions.includes("role.view")
    ? await api<Array<{ key: string; name: string }>>("/roles")
        .then((r) => r.data)
        .catch(() => [])
    : [];

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("people.invite")}
        description={t("invite.desc")}
      />

      <InviteForm roles={roles} />
    </div>
  );
}
