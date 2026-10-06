<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Dns;

use App\Modules\Identity\Domain\Contract\TxtRecords;

/**
 * TXT records through the system resolver.
 *
 * `dns_get_record` returns false — and warns — for a name that does not
 * resolve; both read here as "no records", which is the honest answer to a
 * person who has not published one yet.
 */
final class SystemTxtRecords implements TxtRecords
{
    public function at(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            // Long values arrive split into `entries`; `txt` is them joined.
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = trim($record['txt']);
            }
        }

        return $out;
    }
}
