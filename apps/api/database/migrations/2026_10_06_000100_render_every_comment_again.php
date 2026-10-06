<?php

declare(strict_types=1);

use App\Modules\Collaboration\Application\Service\MarkdownRenderer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-render every stored comment with the fixed renderer.
 *
 * `body_html` is a cache of what the renderer produced when the comment was
 * written, and the web app renders it as HTML. Fixing the renderer (a mention
 * inside a link URL could break out of the href and add attributes such as
 * onfocus=) fixes only what is written from now on; a comment crafted before
 * the fix would keep its stored payload forever. So the cache is rebuilt from
 * the markdown, which was never trusted and is still the source of truth.
 *
 * Every organization's rows, in id order and in chunks — this runs as the
 * migration owner, outside any tenant, which is what a whole-table repair is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renderer = app(MarkdownRenderer::class);

        DB::table('comments')
            ->select(['id', 'body_markdown', 'body_html'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $comments) use ($renderer): void {
                /** @var object{id: string, body_markdown: string, body_html: string} $comment */
                foreach ($comments as $comment) {
                    $html = $renderer->render((string) $comment->body_markdown);

                    if ($html !== $comment->body_html) {
                        DB::table('comments')->where('id', $comment->id)->update(['body_html' => $html]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the previous HTML is exactly what this removes.
    }
};
