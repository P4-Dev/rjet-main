<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AttachmentBatchItemStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Classified = 'classified';
    case Renamed = 'renamed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return __('enums.attachment_batch_item_status.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Classified => 'info',
            self::Renamed => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Classified => 'heroicon-o-tag',
            self::Renamed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }
}
