<?php

declare(strict_types=1);

/**
 * Route registration is delegated to modules.
 *
 * A single monolithic route file becomes a merge-conflict magnet and hides
 * which module owns which endpoint. Each module's service provider loads its
 * own routes under /api/v1.
 */
