<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Rows to a real .xlsx (ADR 0011, the part that was refused rather than faked).
 *
 * The API answered 422 for this format from the day exports shipped, naming the
 * missing writer, because a CSV renamed to `.xlsx` opens in Excel, looks
 * entirely correct, and is a lie the next program to read it discovers. That
 * refusal was the placeholder; this is the thing it was holding a place for.
 *
 * Three decisions that are the whole reason this is not four lines:
 *
 * **Values keep their types.** A CSV is text and every consumer re-guesses;
 * a spreadsheet does not have to. An int arrives as a number and sorts as one,
 * `null` is a genuinely empty cell rather than the string "", and a boolean is
 * written as a boolean. The distinction between a zero and an absence cost this
 * phase four ADRs, and the export is not where it gets dropped.
 *
 * **Nothing is coerced to a formula.** OpenSpout writes inline strings, so a
 * cell whose text begins with `=` stays that text. Worth stating because the
 * CSV path has the opposite hazard and solves it differently — a spreadsheet
 * opening a CSV *does* interpret a leading `=`.
 *
 * **A header row that stays visible.** The header is bold and the top row is
 * frozen: a fifty-thousand-row export scrolled past its first screen is
 * unreadable without it, and "which column is this" is the first question
 * anybody asks of a file somebody else produced.
 */
final class XlsxWriter implements ReportWriter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     */
    public function write(array $columns, array $rows): string
    {
        // OpenSpout streams to a file rather than returning bytes, which is the
        // right shape for its own use and the wrong one for ours: the caller
        // hands the result to storage, and a half-written temp file that
        // escaped a failed build would be worse than no file. So it is written
        // whole and read back once, and the temp file is removed either way.
        $path = tempnam(sys_get_temp_dir(), 'workos-export-');

        if ($path === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);

            // Frozen before the first row is written: the sheet has to exist
            // and nothing may have been added to it yet.
            $writer->getCurrentSheet()->setSheetView(new SheetView(freezeRow: 1));

            $writer->addRow(Row::fromValuesWithStyle($columns, new Style(fontBold: true)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('The export was written but could not be read back.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    public function mimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function extension(): string
    {
        return 'xlsx';
    }
}
