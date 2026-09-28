<?php

declare(strict_types=1);

/**
 * Tenant isolation beyond the application layer (docs/12 §4, ADR 0051).
 *
 * `row_level_security` turns on PostgreSQL Row-Level Security as a second
 * boundary under the application's tenant scope: while an organization is
 * bound, the connection acts as `workos_tenant`, and the database itself
 * refuses another organization's rows — whatever the query forgot.
 *
 * Off by default, because docs/12 §4 names when to turn it on: the first
 * external tenant, a compliance requirement, or a near-miss in review. The
 * policies are installed regardless, and `RowLevelSecurityTest` holds every
 * tenant table to having one, so turning it on is this switch and nothing else.
 */
return [
    'row_level_security' => (bool) env('TENANCY_ROW_LEVEL_SECURITY', false),
];
