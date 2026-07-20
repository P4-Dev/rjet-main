<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PersonType: string implements HasColor, HasIcon, HasLabel
{
    case Pf = 'pf';
    case Pj = 'pj';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pf => __('enums.person_type.pf'),
            self::Pj => __('enums.person_type.pj'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pf => 'gray',
            self::Pj => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pf => 'heroicon-o-user',
            self::Pj => 'heroicon-o-building-office',
        };
    }
}
