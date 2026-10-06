<?php

declare(strict_types=1);

use App\Modules\Collaboration\Application\Service\MarkdownRenderer;

/**
 * The comment renderer, checked the way a browser reads its output: parsed,
 * not searched as a string.
 *
 * The case that made this file exist: the mention pass used to run over links
 * that were already built, so `@name` inside a URL became
 * `<span class="mention">` INSIDE the href — and that quote closed the
 * attribute and handed the rest of the URL to the attacker as attributes of
 * the link (onfocus=, autofocus). A string search for "javascript:" passes
 * that output; a parser does not.
 */

/** @return list<DOMElement> */
function renderedElements(string $markdown): array
{
    $html = app(MarkdownRenderer::class)->render($markdown);

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOERROR);
    libxml_clear_errors();

    $elements = [];

    foreach ($dom->getElementsByTagName('*') as $element) {
        if (! in_array($element->tagName, ['html', 'body'], true)) {
            $elements[] = $element;
        }
    }

    return $elements;
}

it('gives a link only the attributes the renderer writes, whatever the URL holds', function (string $markdown): void {
    $elements = renderedElements($markdown);

    // A payload the renderer turns into plain text has no elements to check,
    // which is a pass — said explicitly, so the case is not reported as empty.
    expect($elements)->toBeArray();

    foreach ($elements as $element) {
        foreach ($element->attributes ?? [] as $attribute) {
            $allowed = match ($element->tagName) {
                'a' => ['href', 'rel', 'target'],
                'span' => ['class'],
                default => [],
            };

            expect($attribute->name)->toBeIn($allowed, "<{$element->tagName}> carries `{$attribute->name}` from: {$markdown}");
        }

        if ($element->tagName === 'a') {
            expect($element->getAttribute('href'))->toMatch('#^https?://#i');
        }
    }
})->with([
    'mention inside the URL' => '[x](https://e.com/@a/autofocus/onfocus=alert(1))',
    'mention with a quote' => '[x](https://e.com/@a"onmouseover="alert(1))',
    'bold inside the URL' => '[x](https://e.com/**a**onfocus=alert(1))',
    'emphasis inside the URL' => '[x](https://e.com/*a*/onfocus=alert(1))',
    'strike inside the URL' => '[x](https://e.com/~~a~~/onfocus=alert(1))',
    'code inside the URL' => '[x](https://e.com/`a`/onfocus=alert(1))',
    'mention in the link text' => '[@Sarah Chen](https://e.com/)',
    'a forged placeholder' => "\u{E000}0\u{E001} [x](https://e.com/)",
]);

it('still renders a link, a mention and formatting side by side', function (): void {
    $html = app(MarkdownRenderer::class)->render('See [the doc](https://example.com/a?b=1&c=2) — **asap**, @Sarah Chen.');

    expect($html)
        ->toContain('<a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer nofollow" target="_blank">the doc</a>')
        ->toContain('<strong>asap</strong>')
        ->toContain('<span class="mention">@Sarah Chen</span>');
});

it('keeps formatting around a link', function (): void {
    expect(app(MarkdownRenderer::class)->render('**[docs](https://example.com)**'))
        ->toContain('<strong><a href="https://example.com"');
});

it('drops a link it cannot trust to plain text', function (string $markdown): void {
    $elements = renderedElements($markdown);

    expect(array_filter($elements, fn (DOMElement $element): bool => $element->tagName === 'a'))->toBe([]);
})->with([
    '[x](javascript:alert(1))',
    '[x](data:text/html;base64,PHNjcmlwdD4=)',
    '[x](//evil.example)',
    '[x](https://e.com/`a`b)',
]);
