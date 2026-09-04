import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { Button } from "@/components/ui/Button";
import { Board } from "@/features/board/Board";
import { ProjectTabs } from "@/features/project/ProjectTabs";
import type { BoardColumn as Column, Project } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The project board.
 *
 * Rendered on the server from ONE endpoint that returns all columns and cards
 * together — not one request per column, which would cost seven round trips
 * before a single card appeared.
 *
 * Drag and drop was "Phase 3's remaining client work" from Phase 3 until now,
 * and in the meantime the cards looked draggable and were not. The move
 * endpoint and its fractional ordering had been in place and proven the whole
 * time (verify-work-constraints.sql PROOF 12); only the interaction was
 * missing. It lives in <Board>, which is a client component for exactly that
 * reason — everything else on this page still renders on the server, from one
 * request. ADR 0012 has the decisions.
 */
export default async function BoardPage({
  params,
}: {
  params: Promise<{ key: string }>;
}) {
  const [me, { key }] = await Promise.all([requireUser(), params]);

  let board: { project: Project; columns: Column[] };

  try {
    ({ data: board } = await api<{ project: Project; columns: Column[] }>(
      `/projects/${key}/board`,
    ));
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // The project's real size, from the columns' own counts — not from the cards
  // on screen, which are the top of each column and nothing more.
  const total = board.columns.reduce((sum, column) => sum + column.total, 0);

  // Overdue can only be counted among the cards that came back, so it is
  // phrased as "at least" rather than presented as the project's total. A
  // number that silently means "of the ones we happened to send" is the kind
  // that gets quoted in a status meeting.
  const overdueVisible = board.columns.reduce(
    (sum, column) => sum + column.items.filter((i) => i.is_overdue).length,
    0,
  );
  const truncated = board.columns.some((column) => column.hidden_count > 0);

  return (
    <div className="space-y-5">
      <PageHeader
        title={board.project.name}
        description={
          overdueVisible > 0
            ? `${total} items · ${truncated ? "at least " : ""}${overdueVisible} overdue`
            : `${total} items`
        }
        action={
          board.project.permissions.create_work ? (
            <Button variant="primary">New work item</Button>
          ) : undefined
        }
      />

      <ProjectTabs projectKey={board.project.key} active="board" />

      {/* Horizontal scroll is correct here and only here: a board IS a
          horizontal surface. On a phone the board is available but not the
          default (docs/08 §6), and it is not draggable there — ADR 0012 §4. */}
      <Board
        columns={board.columns}
        projectKey={board.project.key}
        timeZone={me.user.timezone}
      />
    </div>
  );
}
