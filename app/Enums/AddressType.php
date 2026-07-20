<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AddressType: string implements HasColor, HasIcon, HasLabel
{
    case Main = 'main';
    case Billing = 'billing';
    case Shipping = 'shipping';

    public function getLabel(): string
    {
        return match ($this) {
            self::Main => __('enums.address_type.main'),
            self::Billing => __('enums.address_type.billing'),
            self::Shipping => __('enums.address_type.shipping'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Main => 'primary',
            self::Billing => 'info',
            self::Shipping => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Main => 'heroicon-o-home',
            self::Billing => 'heroicon-o-document-text',
            self::Shipping => 'heroicon-o-truck',
        };
    }
}
