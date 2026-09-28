<?php

declare(strict_types=1);

namespace App\Integrations\Spreadsheet;

interface SpreadsheetWriter
{
    public function open(string $absolutePath): void;

    /**
     * Starts a new sheet (the first call reuses the workbook's initial sheet) and writes the bold header row.
     *
     * @param  list<string>  $headers
     */
    public function addSheet(string $name, array $headers): void;

    /**
     * Scalars and null are always written as text.
     *
     * @param  list<SpreadsheetCell|string|int|float|bool|null>  $cells
     */
    public function addRow(array $cells): void;

    public function close(): void;
}
