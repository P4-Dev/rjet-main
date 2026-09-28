<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ReportDateBasis: string implements HasColor, HasIcon, HasLabel
{
    case DueDate = 'due_date';
    case SettlementDate = 'settlement_date';
    case RequestDate = 'request_date';

    public function getLabel(): string
    {
        return match ($this) {
            self::DueDate => __('enums.report_date_basis.due_date'),
            self::SettlementDate => __('enums.report_date_basis.settlement_date'),
            self::RequestDate => __('enums.report_date_basis.request_date'),
        };
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DueDate => 'heroicon-o-calendar',
            self::SettlementDate => 'heroicon-o-banknotes',
            self::RequestDate => 'heroicon-o-inbox-arrow-down',
        };
    }
}
