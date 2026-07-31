<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentRequestStatus: string implements HasColor, HasIcon, HasLabel
{
    case Requested = 'requested';
    case Launched = 'launched';
    case Settled = 'settled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => __('enums.payment_request_status.requested'),
            self::Launched => __('enums.payment_request_status.launched'),
            self::Settled => __('enums.payment_request_status.settled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Launched => 'info',
            self::Settled => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Requested => 'heroicon-o-clock',
            self::Launched => 'heroicon-o-paper-airplane',
            self::Settled => 'heroicon-o-check-circle',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Requested => $target === self::Launched,
            self::Launched => $target === self::Settled,
            self::Settled => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $this->canTransitionTo($case),
        ));
    }
}
