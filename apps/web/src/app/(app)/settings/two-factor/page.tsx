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
  const me = await requireUser();

  return (
    <div className="space-y-5">
      <PageHeader
        title="Two-factor authentication"
        description="A code from an app on your phone, asked for at sign-in as well as your password."
      />

      <PageBody>
        <TwoFactorPanel enabled={me.user.mfa_enabled} />
      </PageBody>
    </div>
  );
}
