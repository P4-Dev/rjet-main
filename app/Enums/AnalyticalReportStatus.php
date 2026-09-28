<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AnalyticalReportStatus: string implements HasColor, HasIcon, HasLabel
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Generated = 'generated';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => __('enums.analytical_report_status.queued'),
            self::Generating => __('enums.analytical_report_status.generating'),
            self::Generated => __('enums.analytical_report_status.generated'),
            self::Failed => __('enums.analytical_report_status.failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Generating => 'info',
            self::Generated => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-clock',
            self::Generating => 'heroicon-o-arrow-path',
            self::Generated => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }

    /**
     * queued/generating → queued covers the retry of a stale report.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Queued => in_array($target, [self::Generating, self::Failed, self::Queued], true),
            self::Generating => in_array($target, [self::Generated, self::Failed, self::Queued], true),
            self::Failed => $target === self::Queued,
            self::Generated => false,
        };
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Queued, self::Generating], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Generated;
    }

    /**
     * @return list<string>
     */
    public static function inProgressValues(): array
    {
        return [self::Queued->value, self::Generating->value];
    }
}
