<?php

declare(strict_types=1);

namespace App\Integrations\Spreadsheet;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

final class OpenSpoutXlsxSpreadsheetWriter implements SpreadsheetWriter
{
    private ?Writer $writer = null;

    private bool $hasSheet = false;

    private Style $headerStyle;

    private Style $moneyStyle;

    private Style $dateStyle;

    private Style $dateTimeStyle;

    public function __construct()
    {
        $this->headerStyle = (new Style)->setFontBold();
        $this->moneyStyle = (new Style)->setFormat('#,##0.00');
        $this->dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        $this->dateTimeStyle = (new Style)->setFormat('dd/mm/yyyy hh:mm');
    }

    public function open(string $absolutePath): void
    {
        $this->writer = new Writer;
        $this->writer->openToFile($absolutePath);
        $this->hasSheet = false;
    }

    public function addSheet(string $name, array $headers): void
    {
        $writer = $this->writer();
        $sheet = $this->hasSheet ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
        $this->hasSheet = true;

        $sheet->setName($name);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $writer->addRow(new Row(array_map(
            fn (string $header): Cell => new StringCell($header, $this->headerStyle),
            $headers,
        )));
    }

    public function addRow(array $cells): void
    {
        $this->writer()->addRow(new Row(array_map($this->toCell(...), $cells)));
    }

    public function close(): void
    {
        $this->writer?->close();
        $this->writer = null;
        $this->hasSheet = false;
    }

    private function toCell(SpreadsheetCell|string|int|float|bool|null $cell): Cell
    {
        if (! $cell instanceof SpreadsheetCell) {
            return new StringCell(match (true) {
                $cell === null => '',
                is_bool($cell) => $cell ? '1' : '0',
                default => (string) $cell,
            }, null);
        }

        return match ($cell->type) {
            SpreadsheetCell::TYPE_MONEY => new NumericCell((float) $cell->value, $this->moneyStyle),
            SpreadsheetCell::TYPE_NUMBER => new NumericCell(is_int($cell->value) ? $cell->value : (float) $cell->value, null),
            SpreadsheetCell::TYPE_DATE => new DateTimeCell($this->dateValue($cell), $this->dateStyle),
            SpreadsheetCell::TYPE_DATE_TIME => new DateTimeCell($this->dateValue($cell), $this->dateTimeStyle),
            SpreadsheetCell::TYPE_LINK => new FormulaCell($cell->formula(), null, (string) $cell->label),
            default => new StringCell((string) $cell->value, null),
        };
    }

    private function dateValue(SpreadsheetCell $cell): DateTimeInterface
    {
        if (! $cell->value instanceof DateTimeInterface) {
            throw new RuntimeException('Date cell without a date value.');
        }

        return $cell->value;
    }

    private function writer(): Writer
    {
        if ($this->writer === null) {
            throw new RuntimeException('Spreadsheet writer is not open.');
        }

        return $this->writer;
    }
}
