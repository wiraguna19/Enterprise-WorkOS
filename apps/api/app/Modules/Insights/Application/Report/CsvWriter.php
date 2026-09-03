<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

/**
 * Rows to CSV, RFC 4180, with the two details that decide whether a file opens
 * correctly on somebody else's machine.
 *
 * A **UTF-8 BOM**, because Excel on Windows reads a BOM-less file as the
 * system's legacy code page and turns every non-ASCII name in this product's
 * seed — and in its customers — into mojibake. The BOM is three bytes that
 * make the difference between a file that works and a support ticket.
 *
 * **Null is an empty field, false is `false`.** A boolean written as an empty
 * string is indistinguishable from an absent one, and this phase has spent four
 * ADRs on the difference between a zero and an absence; the export is not where
 * that distinction gets dropped.
 */
final class CsvWriter
{
    public const MIME_TYPE = 'text/csv; charset=utf-8';

    /**
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     */
    public function write(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
