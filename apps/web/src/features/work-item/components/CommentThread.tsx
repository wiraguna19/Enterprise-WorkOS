import { Avatar } from "@/components/ui/Avatar";

type Comment = {
  id: string;
  author: { membership_id: string; name: string | null; avatar_url: string | null };
  body_html: string;
  created_at: string;
  edited: boolean;
};

/**
 * The comment body is rendered from server-produced HTML.
 *
 * `dangerouslySetInnerHTML` is used deliberately and safely here: the HTML was
 * produced by MarkdownRenderer, which escapes first and emits only an
 * allowlisted set of tags, and it is verified by a DOM-level XSS harness
 * (infra/docker/verify-renderer.php). The alternative — sanitising in the
 * browser — puts a security boundary in code an attacker can inspect and in a
 * dependency that can regress silently (docs/06 §3).
 */
export function CommentThread({
  comments,
  timeZone,
  canComment,
}: {
  comments: Comment[];
  timeZone: string;
  canComment: boolean;
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
            <li key={comment.id} className="flex gap-3">
              <Avatar
                id={comment.author.membership_id}
                name={comment.author.name ?? "?"}
                size="lg"
              />

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
                  {comment.edited && <span className="text-caption text-n-500">edited</span>}
                </p>

                <div
                  className="prose-comment mt-1 max-w-[72ch] text-body text-n-700"
                  dangerouslySetInnerHTML={{ __html: comment.body_html }}
                />
              </div>
            </li>
          ))}
        </ol>
      )}

      {canComment ? (
        <form className="flex items-start gap-2 border-t border-n-100 pt-4">
          <textarea
            name="body"
            rows={2}
            placeholder="Write a comment…  @name to mention"
            className="min-h-[2.5rem] flex-1 resize-y rounded-sm border border-n-200 bg-n-0 px-2.5 py-1.5 text-body text-n-900 outline-none transition-colors placeholder:text-n-300 focus:border-a-500"
          />
          <button
            type="submit"
            className="h-8 shrink-0 rounded-sm bg-a-500 px-3 text-body font-medium text-white transition-colors hover:bg-a-700"
          >
            Send
          </button>
        </form>
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
