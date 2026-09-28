<?php

declare(strict_types=1);

use App\Integrations\Spreadsheet\SpreadsheetCell;
use App\Integrations\Spreadsheet\SpreadsheetWriter;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @param  list<SpreadsheetCell|string|null>  $cells
 * @return list<Cell>
 */
function writeAndReadSingleRow(array $cells, ?string &$sheetXml = null): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'rjet-writer-test-');
    $writer = app(SpreadsheetWriter::class);
    $writer->open($path);
    $writer->addSheet('Teste', array_map(fn (int $index): string => 'C'.$index, array_keys($cells)));
    $writer->addRow($cells);
    $writer->close();

    $reader = new Reader;
    $reader->open($path);
    $read = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $index => $row) {
            if ($index === 2) {
                $read = $row->getCells();
            }
        }
    }

    $reader->close();

    $zip = new ZipArchive;
    $zip->open($path);
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    return $read;
}

it('keeps user text that looks like a formula as plain text', function (): void {
    $values = ['=1+1', '+SUM(A1)', '-2', '@cmd'];

    $cells = writeAndReadSingleRow(array_map(fn (string $value): SpreadsheetCell => SpreadsheetCell::text($value), $values), $sheetXml);

    expect(array_map(fn ($cell): mixed => $cell->getValue(), $cells))->toBe($values)
        ->and($sheetXml)->not->toContain('<f>')
        ->and(substr_count($sheetXml, 't="inlineStr"'))->toBe(8);
});

it('escapes quotes in hyperlink labels', function (): void {
    $cells = writeAndReadSingleRow([SpreadsheetCell::link('https://rjet.test/a?b=1', 'Nota "fiscal"')]);

    expect($cells[0])->toBeInstanceOf(FormulaCell::class)
        ->and($cells[0]->getValue())->toBe('=HYPERLINK("https://rjet.test/a?b=1","Nota ""fiscal""")');
});

it('writes urls longer than the excel limit as text and logs a warning', function (): void {
    Log::spy();
    $url = 'https://rjet.test/'.str_repeat('a', 260);

    $cells = writeAndReadSingleRow([SpreadsheetCell::link($url, 'Anexo')]);

    expect($cells[0])->toBeInstanceOf(StringCell::class)
        ->and($cells[0]->getValue())->toBe($url);
    Log::shouldHaveReceived('warning')->once();
});
