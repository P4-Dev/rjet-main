<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DepositType: string implements HasColor, HasIcon, HasLabel
{
    case Pix = 'pix';
    case Transfer = 'transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pix => __('enums.deposit_type.pix'),
            self::Transfer => __('enums.deposit_type.transfer'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pix => 'success',
            self::Transfer => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pix => 'heroicon-o-qr-code',
            self::Transfer => 'heroicon-o-arrows-right-left',
        };
    }
}
