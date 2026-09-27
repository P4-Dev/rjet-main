<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CnabLayout: string implements HasColor, HasIcon, HasLabel
{
    case Itau240 = 'itau_240';

    public function getLabel(): string
    {
        return match ($this) {
            self::Itau240 => __('enums.cnab_layout.itau_240'),
        };
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-building-library';
    }

    public function bankCode(): string
    {
        return match ($this) {
            self::Itau240 => '341',
        };
    }
}
