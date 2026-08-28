<?php

declare(strict_types=1);

/**
 * XSS harness for MarkdownRenderer — a security boundary, tested as one.
 *
 * Runs the real class against hostile input without booting the framework, and
 * asserts against the parsed DOM rather than the raw string. That distinction
 * matters: `<p>&lt;img onerror=x&gt;</p>` CONTAINS the text "onerror" but has
 * no element and no attribute, so it is inert. A string-matching harness
 * reports it as a failure and trains everyone to ignore the output.
 *
 * What is actually checked:
 *   - no element outside the allowlist exists in the output
 *   - no element carries an event handler or style attribute
 *   - every href uses http(s)
 *   - legitimate formatting still renders (or the sanitizer is just deleting
 *     the feature and calling it security)
 *
 * Run: php infra/docker/verify-renderer.php
 */

spl_autoload_register(function (string $class): void {
    $path = __DIR__.'/../../apps/api/app/'.str_replace(
        ['App\\Modules\\', '\\'],
        ['Modules/', '/'],
        $class,
    ).'.php';

    if (file_exists($path)) {
        require $path;
    }
});

use App\Modules\Collaboration\Application\Service\MarkdownRenderer;

$renderer = new MarkdownRenderer;

const ALLOWED_TAGS = [
    'html', 'body', 'p', 'br', 'strong', 'em', 'del', 'code', 'pre',
    'ul', 'ol', 'li', 'blockquote', 'a', 'span',
];

const ALLOWED_ATTRIBUTES = ['href', 'rel', 'target', 'class'];

/**
 * @return list<string> the violations found in the rendered output
 */
function inspect(string $html): array
{
    $violations = [];

    if (trim(strip_tags($html)) === '' && trim($html) === '') {
        return $violations;
    }

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="UTF-8">'.$html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//*') ?: [] as $element) {
        /** @var DOMElement $element */
        $tag = strtolower($element->nodeName);

        if (! in_array($tag, ALLOWED_TAGS, true)) {
            $violations[] = "element <{$tag}>";
        }

        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            if (! in_array($name, ALLOWED_ATTRIBUTES, true)) {
                $violations[] = "attribute {$name} on <{$tag}>";

                continue;
            }

            if ($name === 'href' && ! preg_match('#^https?://#i', $value)) {
                $violations[] = "non-http href: {$value}";
            }
        }
    }

    return $violations;
}

$attacks = [
    'script tag' => '<script>alert(1)</script>',
    'img onerror' => '<img src=x onerror=alert(1)>',
    'svg onload' => '<svg onload=alert(1)>',
    'javascript: link' => '[click me](javascript:alert(1))',
    'uppercase JS scheme' => '[x](JaVaScRiPt:alert(1))',
    'data: URI link' => '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
    'protocol-relative link' => '[x](//evil.example/x)',
    'vbscript scheme' => '[x](vbscript:msgbox(1))',
    'iframe' => '<iframe src="https://evil.example"></iframe>',
    'style expression' => '<div style="background:url(javascript:alert(1))">x</div>',
    'onmouseover attribute' => '<a href="https://ok.example" onmouseover="alert(1)">hi</a>',
    'double-encoded script' => '&lt;script&gt;alert(1)&lt;/script&gt;',
    'script inside code fence' => "```\n<script>alert(1)</script>\n```",
    'markdown link plus html' => '[x](https://ok.example)<script>alert(1)</script>',
    'form injection' => '<form action="https://evil.example"><input name=p></form>',
    'object embed' => '<object data="evil.swf"></object>',
    'meta refresh' => '<meta http-equiv="refresh" content="0;url=https://evil.example">',
    'base tag' => '<base href="https://evil.example/">',
    'unclosed script tag' => '<script src=//evil.example/x.js',
    'null byte in tag' => "<scri\0pt>alert(1)</script>",
    'mention spoof' => '@Ahmad <script>alert(1)</script>',
    'nested markdown in href' => '[**x**](https://ok.example")',
    'html entity href' => '[x](&#106;avascript:alert(1))',
];

$failures = 0;

foreach ($attacks as $name => $input) {
    $output = $renderer->render($input);
    $violations = inspect($output);

    if ($violations !== []) {
        $failures++;
        printf("FAIL %-26s %s\n     %s\n", $name, substr($output, 0, 70), implode('; ', $violations));

        continue;
    }

    printf("ok   %-26s %s\n", $name, substr($output, 0, 70));
}

// Legitimate formatting must survive. A sanitizer that strips everything is
// not secure, it is broken.
$positives = [
    '**bold**' => '<strong>bold</strong>',
    '*italic*' => '<em>italic</em>',
    '~~struck~~' => '<del>struck</del>',
    '`inline code`' => '<code>inline code</code>',
    "```\nblock\n```" => '<pre><code>block</code></pre>',
    '[link](https://ok.example)' => 'href="https://ok.example"',
    '[link](https://ok.example)#rel' => 'rel="noopener noreferrer nofollow"',
    '@Sarah Chen please review' => 'class="mention"',
];

echo "\n";

foreach ($positives as $input => $expected) {
    $output = $renderer->render(str_replace('#rel', '', $input));

    if (! str_contains($output, $expected)) {
        $failures++;
        printf("FAIL formatting %-24s got %s, expected %s\n", $input, $output, $expected);

        continue;
    }

    printf("ok   formatting %-24s %s\n", $input, substr($output, 0, 58));
}

$total = count($attacks) + count($positives);

echo "\n", $failures === 0
    ? "All {$total} renderer cases passed: no disallowed element, attribute, or scheme survives.\n"
    : "{$failures} of {$total} renderer cases FAILED.\n";

exit($failures === 0 ? 0 : 1);
