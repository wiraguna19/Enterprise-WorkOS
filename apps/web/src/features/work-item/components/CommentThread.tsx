"use client";

import { useState, useTransition } from "react";
import { Avatar } from "@/components/ui/Avatar";
import { Button } from "@/components/ui/Button";
import { editComment, postComment } from "../actions";
import type { Comment } from "../types";

/**
 * The comment body is rendered from server-produced HTML.
 *
 * `dangerouslySetInnerHTML` is used deliberately and safely here: the HTML was
 * produced by MarkdownRenderer, which escapes first and emits only an
 * allowlisted set of tags, and it is verified by a DOM-level XSS harness
 * (infra/docker/verify-renderer.php). The alternative — sanitising in the
 * browser — puts a security boundary in code an attacker can inspect and in a
 * dependency that can regress silently (docs/06 §3).
 *
 * This became a client component when the composer was wired up. Until then the
 * form had no action at all: a textarea, a Send button, and a plain `<form>`
 * that navigated the page back to itself and dropped the text. It looked
 * finished from every angle — the endpoint existed, its tests passed, and the
 * reachability guard saw the page fetch `/work-items/{reference}/comments` and
 * counted the POST as reached. **A write sharing a path with a read hides
 * behind it.**
 *
 * Editing is offered only on your own comments, and only because the API says
 * the same thing independently (403, naming the rule). What the interface
 * decides is who is OFFERED the control; who may use it is not a question a
 * client gets to answer.
 */
export function CommentThread({
  reference,
  comments,
  timeZone,
  canComment,
  membershipId,
}: {
  reference: string;
  comments: Comment[];
  timeZone: string;
  canComment: boolean;
  /** Whose comments carry an edit control — identity, not permission. */
  membershipId: string;
}) {
  return (
    <div className="space-y-4">
      {comments.length === 0 ? (
        <p className="text-body text-n-500">
          No comments yet. Use <span className="font-mono text-body-sm">@name</span> to pull
          someone in.
        </p>
      ) : (
        <ol className="space-y-4">
          {comments.map((comment) => (
            <CommentRow
              key={comment.id}
              reference={reference}
              comment={comment}
              timeZone={timeZone}
              mine={comment.author.membership_id === membershipId}
            />
          ))}
        </ol>
      )}

      {canComment ? (
        <Composer reference={reference} />
      ) : (
        // Explained rather than hidden: a control that vanishes makes users
        // think the app is broken; one that explains itself teaches the model
        // (docs/07 §4).
        <p className="border-t border-n-100 pt-4 text-caption text-n-500">
          You have read-only access to this work item.
        </p>
      )}
    </div>
  );
}

function CommentRow({
  reference,
  comment,
  timeZone,
  mine,
}: {
  reference: string;
  comment: Comment;
  timeZone: string;
  mine: boolean;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(comment.body_markdown);
  const [error, setError] = useState<string | null>(null);
  const [saving, startTransition] = useTransition();

  const save = () =>
    startTransition(async () => {
      const result = await editComment(reference, comment.id, draft);
      setError(result.error);

      if (result.error === null) setEditing(false);
    });

  return (
    <li className="flex gap-3">
      <Avatar id={comment.author.membership_id} name={comment.author.name ?? "?"} size="lg" />

      <div className="min-w-0 flex-1">
        <p className="flex items-baseline gap-2">
          <span className="font-medium text-n-900">{comment.author.name}</span>
          <span className="text-caption text-n-500 tabular-nums">
            {new Intl.DateTimeFormat("en-GB", {
              day: "numeric",
              month: "short",
              hour: "2-digit",
              minute: "2-digit",
              timeZone,
            }).format(new Date(comment.created_at))}
          </span>
          {/* "edited" stays visible after an edit, because a comment someone
              replied to may no longer say what they replied to. */}
          {comment.edited && <span className="text-caption text-n-500">edited</span>}

          {mine && !editing && (
            <button
              type="button"
              onClick={() => {
                setDraft(comment.body_markdown);
                setError(null);
                setEditing(true);
              }}
              className="text-caption text-n-500 underline-offset-2 hover:text-n-900 hover:underline"
            >
              Edit
            </button>
          )}
        </p>

        {editing ? (
          <div className="mt-1 space-y-2">
            {/* Seeded with the markdown SOURCE, not the rendered HTML: editing
                a comment must not silently rewrite it into whatever the
                renderer produced. */}
            <textarea
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              rows={3}
              aria-label="Edit your comment"
              className="w-full resize-y rounded-sm border border-n-200 bg-n-0 px-2.5 py-1.5 text-body text-n-900 outline-none transition-colors focus:border-a-500"
            />

            <div className="flex flex-wrap items-center gap-2">
              <Button variant="primary" size="sm" disabled={saving} onClick={save}>
                {saving ? "Saving…" : "Save"}
              </Button>
              <Button variant="ghost" size="sm" disabled={saving} onClick={() => setEditing(false)}>
                Cancel
              </Button>
              {error && (
                <span role="alert" className="text-caption text-s-danger">
                  {error}
                </span>
              )}
            </div>
          </div>
        ) : (
          <div
            className="prose-comment mt-1 max-w-[72ch] text-body text-n-700"
            dangerouslySetInnerHTML={{ __html: comment.body_html }}
          />
        )}
      </div>
    </li>
  );
}

function Composer({ reference }: { reference: string }) {
  const [body, setBody] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [sending, startTransition] = useTransition();

  const send = () =>
    startTransition(async () => {
      const result = await postComment(reference, body);
      setError(result.error);

      // Cleared only on success. A failed send that empties the box loses what
      // the person wrote, which is the one thing a comment box must never do.
      if (result.error === null) setBody("");
    });

  return (
    <form
      className="border-t border-n-100 pt-4"
      onSubmit={(event) => {
        event.preventDefault();
        send();
      }}
    >
      <div className="flex items-start gap-2">
        <textarea
          name="body"
          value={body}
          onChange={(event) => setBody(event.target.value)}
          rows={2}
          placeholder="Write a comment…  @name to mention"
          className="min-h-[2.5rem] flex-1 resize-y rounded-sm border border-n-200 bg-n-0 px-2.5 py-1.5 text-body text-n-900 outline-none transition-colors placeholder:text-n-300 focus:border-a-500"
        />
        <Button type="submit" variant="primary" disabled={sending || body.trim() === ""}>
          {sending ? "Sending…" : "Send"}
        </Button>
      </div>

      {error && (
        <p role="alert" className="mt-1.5 text-caption text-s-danger">
          {error}
        </p>
      )}
    </form>
  );
}
