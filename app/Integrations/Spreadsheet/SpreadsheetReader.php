<?php

declare(strict_types=1);

namespace App\Integrations\Spreadsheet;

use Generator;

interface SpreadsheetReader
{
    /**
     * Yields data rows as ['row' => int, 'values' => array<string, mixed>] keyed by header names.
     * Row 1 is the header; data rows start at 2.
     *
     * @return Generator<int, array{row: int, values: array<string, mixed>}>
     */
    public function rows(string $absolutePath): Generator;
}
