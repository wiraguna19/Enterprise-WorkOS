<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use App\Modules\Insights\Domain\Exception\UnsupportedExportFormat;

/**
 * The formats an export can be asked for — one list, in one place.
 *
 * There were two lists before: a `SUPPORTED_FORMATS` constant on the
 * controller, which decided what a request could ask for, and the writer the
 * job happened to be injected with, which decided what it got. They agreed
 * because there was only one format. Adding the second is exactly when two
 * lists start to drift, and the drift would be a request accepted for a file
 * nothing can build — a pending export that never becomes anything.
 *
 * So the controller validates by asking this, and the job builds by asking this.
 */
final class WriterRegistry
{
    /**
     * Non-empty by construction, and typed that way so callers do not have to
     * pretend the product might ship with no way to write a file.
     *
     * @var non-empty-array<string, ReportWriter>
     */
    private array $writers;

    public function __construct(CsvWriter $csv)
    {
        $this->writers = [
            $csv->extension() => $csv,
        ];
    }

    public function has(string $format): bool
    {
        return isset($this->writers[$format]);
    }

    public function get(string $format): ReportWriter
    {
        return $this->writers[$format]
            ?? throw new UnsupportedExportFormat(
                "{$format} is not a format this can write.",
                ['supported' => $this->formats()],
            );
    }

    /** @return non-empty-list<string> */
    public function formats(): array
    {
        return array_keys($this->writers);
    }
}
