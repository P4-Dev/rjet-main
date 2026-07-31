<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AttachmentType: string implements HasColor, HasIcon, HasLabel
{
    case Boleto = 'boleto';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Boleto => __('enums.attachment_type.boleto'),
            self::Other => __('enums.attachment_type.other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Boleto => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Boleto => 'heroicon-o-document-text',
            self::Other => 'heroicon-o-paper-clip',
        };
    }
}
