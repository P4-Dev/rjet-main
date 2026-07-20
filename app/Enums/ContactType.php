<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ContactType: string implements HasColor, HasIcon, HasLabel
{
    case Main = 'main';
    case Billing = 'billing';
    case Technical = 'technical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Main => __('enums.contact_type.main'),
            self::Billing => __('enums.contact_type.billing'),
            self::Technical => __('enums.contact_type.technical'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Main => 'primary',
            self::Billing => 'info',
            self::Technical => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Main => 'heroicon-o-user',
            self::Billing => 'heroicon-o-currency-dollar',
            self::Technical => 'heroicon-o-wrench-screwdriver',
        };
    }
}
