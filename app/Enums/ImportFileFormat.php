<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ImportFileFormat: string implements HasColor, HasIcon, HasLabel
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function getLabel(): ?string
    {
        return __('enums.import_file_format.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Csv => 'gray',
            self::Xlsx => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Csv => 'heroicon-o-document-text',
            self::Xlsx => 'heroicon-o-table-cells',
        };
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Csv => ['text/csv', 'text/plain', 'application/csv'],
            self::Xlsx => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
            ],
        };
    }
}
