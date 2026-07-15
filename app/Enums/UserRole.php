<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasIcon, HasLabel
{
    case Cliente = 'cliente';
    case Operador = 'operador';
    case Adm = 'adm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cliente => __('enums.user_role.cliente'),
            self::Operador => __('enums.user_role.operador'),
            self::Adm => __('enums.user_role.adm'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cliente => 'gray',
            self::Operador => 'info',
            self::Adm => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cliente => 'heroicon-o-user',
            self::Operador => 'heroicon-o-user-group',
            self::Adm => 'heroicon-o-shield-check',
        };
    }

    public function canManageRegistrations(): bool
    {
        return $this === self::Adm;
    }

    public function seesAllBranches(): bool
    {
        return $this === self::Operador || $this === self::Adm;
    }
}
