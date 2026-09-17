<?php

declare(strict_types=1);

/**
 * A nav entry the interface hides must be a page the interface refuses.
 *
 * `AppShell` gates seven entries on a permission, so somebody without it never
 * sees the link. None of those PAGES checked anything, and a URL is typed,
 * pasted, bookmarked and followed from an old message.
 *
 * The two failure modes were both found by opening the product as a manager:
 *
 *   - `/reports` reads the flow endpoint with no fallback, so a 403 became an
 *     unhandled error and the reader got "Something went wrong".
 *   - `/departments` and `/recurring` DO fall back to an empty list, so the
 *     same 403 rendered "No departments yet" — a confident lie about somebody
 *     else's organization, and the worse of the two by a distance.
 *
 * This is the web-side twin of `EveryEndpointIsReachableTest`: that one asks
 * what calls an endpoint, this one asks what refuses a page. Crude on purpose —
 * a grep, not a type system — because a crude check that runs on every commit
 * beats a precise one nobody writes.
 *
 * It carried one exemption, and how that was settled is worth keeping.
 * `/settings` was gated in the nav on `organization.view` — a permission no
 * route, policy or service on the server had ever asked for — so adding a
 * page-level check would have made the pretence deeper rather than smaller: the
 * interface would refuse on a rule the API did not enforce, and the same person
 * could still reach everything behind it through the API. ADR 0028 gave the
 * permission meaning, and the gate came OFF the nav entry rather than onto the
 * page: half of what the settings index lists is the reader's own account, and
 * hiding somebody's own notifications behind an organization-reading permission
 * refuses the wrong thing. The index filters itself, entry by entry.
 */
it('refuses every page whose nav entry is gated', function (): void {
    $web = dirname(__DIR__, 3).'/web/src';

    if (! is_dir($web)) {
        $this->markTestSkipped('The web app is not part of this checkout.');
    }

    $shell = (string) file_get_contents($web.'/components/AppShell.tsx');

    // { href: "/reports", label: "Flow", permission: "report.view" }
    preg_match_all(
        '/href:\s*"(\/[a-z-]+)"[^}]*permission:\s*"([a-z_.]+)"/',
        $shell,
        $matches,
        PREG_SET_ORDER,
    );

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $href, $permission]) {
        $page = $web.'/app/(app)'.$href.'/page.tsx';

        $this->assertFileExists($page, "{$href} is in the nav and has no page.");

        $source = (string) file_get_contents($page);

        // `assertStringContainsString`, not `expect()->toContain()`: Pest's
        // matcher is VARIADIC, so the explanation below would have been read as
        // a second needle and searched for in the page — which is how this test
        // failed on its first run, against six pages that were all correct.
        $this->assertStringContainsString(
            "permissions.includes(\"{$permission}\")",
            $source,
            "{$href} is hidden from the nav without `{$permission}` and does not refuse "
                .'anybody who reaches it directly. Add the check the audit screen makes — '
                .'notFound() when the permission is absent. 404 rather than 403, like every '
                .'other refusal here: whether the thing exists is not the page to disclose.',
        );

        $this->assertStringContainsString(
            'notFound()',
            $source,
            "{$href} names the permission but never refuses.",
        );
    }
});
