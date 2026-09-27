<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AttachmentBatchDestinationType: string implements HasColor, HasIcon, HasLabel
{
    case PaymentRequest = 'payment_request';
    case Supplier = 'supplier';
    case OperationalCategory = 'operational_category';

    public function getLabel(): ?string
    {
        return __('enums.attachment_batch_destination_type.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PaymentRequest => 'primary',
            self::Supplier => 'info',
            self::OperationalCategory => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PaymentRequest => 'heroicon-o-banknotes',
            self::Supplier => 'heroicon-o-building-storefront',
            self::OperationalCategory => 'heroicon-o-folder',
        };
    }
}
