<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CnabFileStatus: string implements HasColor, HasIcon, HasLabel
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Generated = 'generated';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => __('enums.cnab_file_status.queued'),
            self::Generating => __('enums.cnab_file_status.generating'),
            self::Generated => __('enums.cnab_file_status.generated'),
            self::Failed => __('enums.cnab_file_status.failed'),
            self::Superseded => __('enums.cnab_file_status.superseded'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Generating => 'info',
            self::Generated => 'success',
            self::Failed => 'danger',
            self::Superseded => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-clock',
            self::Generating => 'heroicon-o-arrow-path',
            self::Generated => 'heroicon-o-document-check',
            self::Failed => 'heroicon-o-exclamation-triangle',
            self::Superseded => 'heroicon-o-archive-box-x-mark',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Queued => in_array($target, [self::Generating, self::Failed], true),
            self::Generating => in_array($target, [self::Generated, self::Failed], true),
            self::Generated => $target === self::Superseded,
            self::Failed, self::Superseded => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Superseded], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Generating, self::Generated], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Queued, self::Generating], true);
    }

    /**
     * Must stay in sync with the cnab_files_settlement_active_unique index predicate.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Queued->value, self::Generating->value, self::Generated->value];
    }

    /**
     * @return list<string>
     */
    public static function inProgressValues(): array
    {
        return [self::Queued->value, self::Generating->value];
    }
}
