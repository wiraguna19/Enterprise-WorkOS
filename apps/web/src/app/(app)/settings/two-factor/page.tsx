import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { TwoFactorPanel } from "@/features/auth/TwoFactorPanel";
import { requireUser } from "@/lib/auth";

/**
 * A second factor for this account (ADR 0030).
 *
 * No permission gates it, like the session list beside it: this is an account
 * deciding about itself, and a key that could be withheld would mean an
 * administrator refusing somebody their own security settings.
 *
 * `mfa_enabled` has been in `/auth/me` since Phase 1 and nothing read it,
 * because nothing could turn it on.
 */
export default async function TwoFactorPage() {
  // The one page a confined person may see — so the one page that says so
  // rather than sending them somewhere else (ADR 0033).
  const me = await requireUser({ allowUnenrolled: true });

  return (
    <div className="space-y-5">
      <PageHeader
        title="Two-factor authentication"
        description={
          me.organization.requires_second_factor && !me.user.mfa_enabled
            ? `${me.organization.name} requires a second factor. Until you set one up, this is the only page you can use — nothing else has been taken away.`
            : "A code from an app on your phone, asked for at sign-in as well as your password."
        }
      />

      <PageBody>
        <TwoFactorPanel enabled={me.user.mfa_enabled} />
      </PageBody>
    </div>
  );
}
