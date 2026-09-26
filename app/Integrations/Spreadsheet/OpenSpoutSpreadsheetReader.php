<?php

declare(strict_types=1);

namespace App\Integrations\Spreadsheet;

use App\Exceptions\ImportException;
use Generator;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Reader\CSV\RowIterator as CsvRowIterator;
use OpenSpout\Reader\XLSX\RowIterator as XlsxRowIterator;
use Throwable;

final class OpenSpoutSpreadsheetReader implements SpreadsheetReader
{
    public function rows(string $absolutePath): Generator
    {
        try {
            $reader = ReaderFactory::createFromFile($absolutePath);
            $reader->open($absolutePath);
        } catch (IOException|UnsupportedTypeException|Throwable $e) {
            throw ImportException::unreadableSpreadsheet($e);
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rowNumber = 0;

                /** @var CsvRowIterator|XlsxRowIterator $rowIterator */
                $rowIterator = $sheet->getRowIterator();

                foreach ($rowIterator as $row) {
                    $rowNumber++;
                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();
                        $cells[] = $value instanceof \DateTimeInterface
                            ? $value->format('Y-m-d')
                            : (is_scalar($value) || $value === null ? $value : (string) $value);
                    }

                    if ($rowNumber === 1) {
                        $headers = array_map(
                            static fn (mixed $h): string => trim((string) $h),
                            $cells,
                        );

                        continue;
                    }

                    if ($headers === null || $headers === []) {
                        throw ImportException::emptySpreadsheet();
                    }

                    $values = [];
                    foreach ($headers as $index => $header) {
                        if ($header === '') {
                            continue;
                        }
                        $values[$header] = $cells[$index] ?? null;
                    }

                    if ($this->isEmptyRow($values)) {
                        continue;
                    }

                    yield [
                        'row' => $rowNumber,
                        'values' => $values,
                    ];
                }

                break;
            }
        } catch (ImportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImportException::unreadableSpreadsheet($e);
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
