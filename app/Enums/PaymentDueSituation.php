<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentDueSituation: string implements HasColor, HasIcon, HasLabel
{
    case Paid = 'paid';
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';

    public static function for(PaymentRequest $paymentRequest, CarbonImmutable $today): self
    {
        if ($paymentRequest->status === PaymentRequestStatus::Settled) {
            return self::Paid;
        }

        if ($paymentRequest->due_date->toDateString() < $today->toDateString()) {
            return self::Overdue;
        }

        return self::Upcoming;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => __('enums.payment_due_situation.paid'),
            self::Upcoming => __('enums.payment_due_situation.upcoming'),
            self::Overdue => __('enums.payment_due_situation.overdue'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Upcoming => 'warning',
            self::Overdue => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Paid => 'heroicon-o-check-circle',
            self::Upcoming => 'heroicon-o-calendar-days',
            self::Overdue => 'heroicon-o-exclamation-triangle',
        };
    }
}
