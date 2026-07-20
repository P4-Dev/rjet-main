<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasIcon, HasLabel
{
    case Boleto = 'boleto';
    case Deposit = 'deposit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Boleto => __('enums.payment_method.boleto'),
            self::Deposit => __('enums.payment_method.deposit'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Boleto => 'warning',
            self::Deposit => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Boleto => 'heroicon-o-document-text',
            self::Deposit => 'heroicon-o-banknotes',
        };
    }
}
