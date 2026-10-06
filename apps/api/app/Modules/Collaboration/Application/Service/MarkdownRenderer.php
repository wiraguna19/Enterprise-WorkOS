<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Application\Service;

/**
 * Markdown → HTML, through a strict allowlist.
 *
 * This is a security boundary, so it is written as one: an ALLOWLIST of tags
 * and attributes, never a denylist of dangerous ones. Denylists lose — there is
 * always another vector (`javascript:` URLs, `data:` URIs, `onerror`, SVG,
 * malformed entities), and the attacker only needs to find one.
 *
 * Deliberately a small subset of markdown. A comment box is not a CMS; every
 * additional construct is another thing to get wrong.
 */
final class MarkdownRenderer
{
    /** Tags the renderer may emit. Nothing else survives. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'del', 'code', 'pre',
        'ul', 'ol', 'li', 'blockquote', 'a', 'span',
    ];

    /** Marks a finished link while the passes after it run (private-use code points). */
    private const OPEN = "\u{E000}";

    private const CLOSE = "\u{E001}";

    public function render(string $markdown): string
    {
        // The two characters the link placeholders are built from cannot come
        // from the author: a forged placeholder would splice in a link they
        // never wrote.
        $markdown = str_replace([self::OPEN, self::CLOSE], '', $markdown);

        // Escape FIRST. Everything after this operates on text that can no
        // longer introduce markup, so the formatting rules below can only ever
        // produce the tags they explicitly write.
        $html = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Fenced code blocks before anything else — their contents must not be
        // interpreted as formatting.
        $html = preg_replace_callback(
            '/```(\w*)\n(.*?)```/s',
            static fn (array $m): string => '<pre><code>'.trim($m[2]).'</code></pre>',
            $html,
        ) ?? $html;

        $html = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $html) ?? $html;

        // Links are finished HERE and set aside until every other pass has
        // run. They used to be built in the middle of the pipeline, and the
        // mention pass after them rewrote `@name` INSIDE an href into
        // `<span class="mention">` — whose quote closed the attribute and
        // turned the rest of the URL into attributes of the link: onfocus=,
        // autofocus, anything. A pass that writes markup must never run over
        // markup another pass has already written.
        $links = [];
        $html = $this->renderLinks($html, $links);

        $html = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?![\*\w])/', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $html) ?? $html;
        $html = $this->renderMentions($html);

        $html = preg_replace_callback(
            '/'.self::OPEN.'(\d+)'.self::CLOSE.'/u',
            static fn (array $m): string => $links[(int) $m[1]] ?? '',
            $html,
        ) ?? $html;

        $html = $this->renderParagraphs($html);

        return $this->stripDisallowedTags($html);
    }

    /**
     * Only http(s) links survive, and each is replaced by a placeholder until
     * the end of rendering (see render()).
     *
     * `javascript:`, `data:`, and protocol-relative URLs are dropped to plain
     * text rather than rewritten — a rewritten hostile link is still a link.
     * The URL is checked AFTER escaping, so it can hold no quote and no angle
     * bracket; anything else that is not plain URL text (markup an earlier
     * pass wrote, for one) makes it not a link at all.
     * `rel="noopener"` because a target="_blank" link without it hands the
     * opener window to the destination.
     *
     * @param  list<string>  $links  receives the finished links, by placeholder number
     *
     * @param-out list<string> $links
     */
    private function renderLinks(string $html, array &$links): string
    {
        return preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/',
            static function (array $m) use (&$links): string {
                $url = $m[2];

                if (preg_match('#^https?://[^\s<>"\'`]+$#i', $url) !== 1) {
                    return $m[1];
                }

                $links[] = sprintf(
                    '<a href="%s" rel="noopener noreferrer nofollow" target="_blank">%s</a>',
                    $url,
                    $m[1],
                );

                return self::OPEN.(count($links) - 1).self::CLOSE;
            },
            $html,
        ) ?? $html;
    }

    /**
     * Mentions become a span, not a link.
     *
     * Resolution to a real membership happens in CommentService against the
     * tenant's active members; the renderer only styles the text. Emitting a
     * profile link here would let a mention of a non-existent person produce a
     * broken or cross-tenant URL.
     */
    private function renderMentions(string $html): string
    {
        return preg_replace(
            '/@([\p{L}][\p{L}\p{N}\'\-]*(?:\s+[\p{L}][\p{L}\p{N}\'\-]*)?)/u',
            '<span class="mention">@$1</span>',
            $html,
        ) ?? $html;
    }

    private function renderParagraphs(string $html): string
    {
        $blocks = preg_split('/\n{2,}/', trim($html)) ?: [];

        return implode('', array_map(
            static function (string $block): string {
                $block = trim($block);

                if ($block === '') {
                    return '';
                }

                if (str_starts_with($block, '<pre>')) {
                    return $block;
                }

                return '<p>'.nl2br($block, false).'</p>';
            },
            $blocks,
        ));
    }

    /**
     * Final backstop.
     *
     * Nothing above should be able to emit a tag outside the allowlist, but the
     * cost of this pass is negligible and the cost of being wrong is an XSS.
     */
    private function stripDisallowedTags(string $html): string
    {
        $allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';

        return strip_tags($html, $allowed);
    }
}
