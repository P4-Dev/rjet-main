<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Schemas;

use App\Enums\AnalyticalReportStatus;
use App\Enums\PaymentRequestStatus;
use App\Models\AnalyticalReport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AnalyticalReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('analytical_reports.sections.filters'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('date_basis')
                            ->label(__('analytical_reports.fields.date_basis'))
                            ->badge(),
                        TextEntry::make('period_start')
                            ->label(__('analytical_reports.fields.period_start'))
                            ->date('d/m/Y'),
                        TextEntry::make('period_end')
                            ->label(__('analytical_reports.fields.period_end'))
                            ->date('d/m/Y'),
                        TextEntry::make('company.name')
                            ->label(__('analytical_reports.fields.company_id'))
                            ->placeholder(__('analytical_reports.fields.all')),
                        TextEntry::make('branch.name')
                            ->label(__('analytical_reports.fields.branch_id'))
                            ->placeholder(__('analytical_reports.fields.all')),
                        TextEntry::make('statuses')
                            ->label(__('analytical_reports.fields.statuses'))
                            ->badge()
                            ->state(fn (AnalyticalReport $record): array => array_values(array_filter(array_map(
                                fn (string $value): ?string => PaymentRequestStatus::tryFrom($value)?->getLabel(),
                                $record->statuses ?? [],
                            ))))
                            ->placeholder(__('analytical_reports.fields.all_statuses')),
                    ]),
                Section::make(__('analytical_reports.sections.result'))
                    ->columns(2)
                    ->extraAttributes(fn (AnalyticalReport $record): array => $record->status->isInProgress()
                        ? ['wire:poll.5s' => 'refreshReport']
                        : [])
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('analytical_reports.fields.status'))
                            ->badge(),
                        TextEntry::make('rows_count')
                            ->label(__('analytical_reports.fields.rows_count')),
                        TextEntry::make('attachments_count')
                            ->label(__('analytical_reports.fields.attachments_count')),
                        TextEntry::make('filename')
                            ->label(__('analytical_reports.fields.filename'))
                            ->placeholder('—'),
                        TextEntry::make('generated_at')
                            ->label(__('analytical_reports.fields.generated_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('failure_reason')
                            ->label(__('analytical_reports.fields.failure_reason'))
                            ->color('danger')
                            ->visible(fn (AnalyticalReport $record): bool => $record->status === AnalyticalReportStatus::Failed)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('common.sections.audit'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label(__('common.fields.created_by'))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('started_at')
                            ->label(__('analytical_reports.fields.started_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
