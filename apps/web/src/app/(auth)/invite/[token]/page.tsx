import { redirect } from "next/navigation";

/**
 * Links made before the token moved into the fragment (`/invite#<token>`).
 *
 * Still honoured — they were handed to people who have not opened them yet —
 * but sent on at once, so the token is not left in the address bar, the
 * history or the next page's referrer.
 */
export default async function LegacyInviteLink({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;

  redirect(`/invite#${encodeURIComponent(token)}`);
}
