<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    case Checking = 'checking';
    case Savings = 'savings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Checking => __('enums.account_type.checking'),
            self::Savings => __('enums.account_type.savings'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Checking => 'info',
            self::Savings => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Checking => 'heroicon-o-banknotes',
            self::Savings => 'heroicon-o-archive-box',
        };
    }
}
