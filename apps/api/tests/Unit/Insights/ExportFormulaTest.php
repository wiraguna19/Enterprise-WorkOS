<?php

declare(strict_types=1);

use App\Modules\Insights\Application\Report\CsvWriter;
use App\Modules\Insights\Application\Report\XlsxWriter;

/**
 * An export never hands a spreadsheet a formula (CSV / formula injection).
 *
 * Titles, names and descriptions are written by anyone in the organization;
 * an export is opened by somebody else, in a spreadsheet, on their own
 * machine. A title of `=HYPERLINK(...)` must arrive as that text.
 */
it('defuses a CSV cell that would start a formula, and leaves numbers alone', function (): void {
    $csv = (new CsvWriter)->write(
        ['title', 'delta'],
        [
            ['=HYPERLINK("https://evil.example","Open")', -3],
            ['+1+1', '-2.5'],
            ['@SUM(A1)', null],
            ['Plain title', 4],
        ],
    );

    $lines = explode("\n", trim(substr($csv, 3)));

    expect($lines[1])->toBe('"\'=HYPERLINK(""https://evil.example"",""Open"")",-3')
        ->and($lines[2])->toBe("'+1+1,-2.5")
        ->and($lines[3])->toBe("'@SUM(A1),")
        // Untouched; quoted only because fputcsv quotes any field with a space.
        ->and($lines[4])->toBe('"Plain title",4');
});

it('writes no formula cell into an xlsx, whatever the text says', function (): void {
    $bytes = (new XlsxWriter)->write(
        ['=title'],
        [['=HYPERLINK("https://evil.example","Open")'], [42]],
    );

    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents((string) $path, $bytes);

    $zip = new ZipArchive;
    $zip->open((string) $path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink((string) $path);

    expect($sheet)->not->toContain('<f>')
        ->and($sheet)->toContain('=HYPERLINK');
});
