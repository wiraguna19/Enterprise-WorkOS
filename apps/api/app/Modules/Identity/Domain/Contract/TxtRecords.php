<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contract;

/**
 * The TXT records published at a DNS name.
 *
 * An interface because the real answer comes from the network: tests answer
 * it themselves, and nothing in this module should resolve DNS in a test run.
 */
interface TxtRecords
{
    /** @return list<string> every TXT string at that name, empty when there are none */
    public function at(string $name): array;
}
