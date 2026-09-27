<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentSettlementStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('enums.payment_settlement_status.draft'),
            self::Settled => __('enums.payment_settlement_status.settled'),
            self::Cancelled => __('enums.payment_settlement_status.cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Settled => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-pencil-square',
            self::Settled => 'heroicon-o-check-badge',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Settled, self::Cancelled], true),
            self::Settled, self::Cancelled => false,
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

    public function isTerminal(): bool
    {
        return in_array($this, [self::Settled, self::Cancelled], true);
    }
}
