<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AttachmentBatchStatus: string implements HasColor, HasIcon, HasLabel
{
    case PendingClassification = 'pending_classification';
    case Classified = 'classified';
    case Renaming = 'renaming';
    case Renamed = 'renamed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return __('enums.attachment_batch_status.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PendingClassification => 'warning',
            self::Classified => 'info',
            self::Renaming => 'primary',
            self::Renamed => 'success',
            self::PartiallyFailed => 'warning',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PendingClassification => 'heroicon-o-tag',
            self::Classified => 'heroicon-o-check',
            self::Renaming => 'heroicon-o-arrow-path',
            self::Renamed => 'heroicon-o-check-circle',
            self::PartiallyFailed => 'heroicon-o-exclamation-triangle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }

    /**
     * PartiallyFailed/Failed → Renaming only re-runs naming for failed items; classification is never reopened.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::PendingClassification => in_array($to, [self::Classified, self::Failed], true),
            self::Classified => $to === self::Renaming,
            self::Renaming => in_array($to, [self::Renamed, self::PartiallyFailed, self::Failed], true),
            self::PartiallyFailed, self::Failed => $to === self::Renaming,
            self::Renamed => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Renamed, self::PartiallyFailed, self::Failed], true);
    }
}
