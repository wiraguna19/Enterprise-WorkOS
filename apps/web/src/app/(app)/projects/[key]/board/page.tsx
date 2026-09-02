import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { Button } from "@/components/ui/Button";
import { BoardColumn } from "@/features/board/BoardColumn";
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
 * Drag and drop is Phase 3's remaining client work: the move endpoint and its
 * fractional ordering are already in place and proven
 * (verify-work-constraints.sql PROOF 12), so the interaction is a client
 * concern rather than a data-model one.
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

  const total = board.columns.reduce((sum, column) => sum + column.items.length, 0);
  const overdue = board.columns.reduce(
    (sum, column) => sum + column.items.filter((i) => i.is_overdue).length,
    0,
  );

  return (
    <div className="space-y-5">
      <PageHeader
        title={board.project.name}
        description={
          overdue > 0
            ? `${total} items · ${overdue} overdue`
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
          default (docs/08 §6). */}
      <div className="-mx-4 overflow-x-auto px-4 pb-4 md:-mx-8 md:px-8">
        <div className="flex gap-4">
          {board.columns.map((column) => (
            <BoardColumn key={column.state.id} column={column} timeZone={me.user.timezone} />
          ))}
        </div>
      </div>
    </div>
  );
}
