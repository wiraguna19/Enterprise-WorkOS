import { notFound } from "next/navigation";
import Link from "next/link";
import { PersonProfile } from "@/features/people/PersonProfile";
import type { PersonDetail } from "@/features/people/types";
import type { WorkItem } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * A person's profile (docs/08 §2).
 *
 * Keyed by membership id rather than a handle: a person's name is not unique
 * and their email is not theirs to leak into a URL that gets pasted around.
 */
export default async function PersonPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  let person: PersonDetail;

  try {
    ({ data: person } = await api<PersonDetail>(`/people/${id}`));
  } catch (error) {
    // 404 covers both "no such person" and "not in your organization" —
    // deliberately indistinguishable (docs/05 §3).
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // Their work, filtered by the SERVER's view of what this viewer may see: the
  // endpoint applies work item visibility before the assignee filter, so this
  // cannot become a way to enumerate work in a private project through the
  // profile of someone who is on it (docs/06 §2).
  const { data: openWork } = await api<WorkItem[]>(
    `/work-items?filter[assignee_id]=${id}` +
      "&filter[state_category]=todo,in_progress,in_review,blocked" +
      "&sort=due_at&limit=10",
  ).catch(() => ({ data: [] as WorkItem[] }));

  return (
    <div className="space-y-4">
      <Link href="/people" className="text-body-sm text-n-500 hover:text-a-700">
        ← People
      </Link>

      <PersonProfile person={person} openWork={openWork} timeZone={me.user.timezone} />
    </div>
  );
}
