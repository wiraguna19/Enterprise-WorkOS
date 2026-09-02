import { redirect } from "next/navigation";

/**
 * `/projects/{key}` is the URL people paste to each other, so it has to lead
 * somewhere (docs/08 §2).
 *
 * docs/08 wants it to land on the reader's last-used view; nothing records that
 * yet, so it lands on Overview — the view that answers "how is this project
 * doing", which is the question someone following a bare project link is
 * usually asking. When last-used views ship, this is the one place to change.
 */
export default async function ProjectPage({
  params,
}: {
  params: Promise<{ key: string }>;
}) {
  const { key } = await params;

  redirect(`/projects/${key}/overview`);
}
