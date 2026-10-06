<?php

declare(strict_types=1);

/**
 * Who may tell the API where a request really came from.
 *
 * The web app calls the API server to server, so without this every request
 * arrives from the web server's address — and every per-address rate limit
 * became one limit shared by the whole product: twenty failed logins from
 * anyone locked everybody out for fifteen minutes.
 *
 * The web app forwards the client's address in X-Forwarded-For, and the API
 * believes that header ONLY when the connection comes from one of these
 * addresses. Anyone else calling the API directly gets their own address used,
 * whatever header they send.
 *
 * Comma-separated addresses or CIDR ranges of the web server(s). The default
 * fits the web app and the API on one host.
 */
return [
    'trusted' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
    ))),
];
