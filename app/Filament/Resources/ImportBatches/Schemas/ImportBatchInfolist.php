<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('import_batches.sections.status'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('import_batches.fields.status'))
                            ->badge(),
                        TextEntry::make('template_label')
                            ->label(__('import_batches.fields.template'))
                            ->state(fn (ImportBatch $record): string => sprintf(
                                '%s (v%d)',
                                $record->templateVersion?->template?->name ?? '—',
                                $record->templateVersion?->version ?? 0,
                            )),
                        TextEntry::make('original_filename')
                            ->label(__('import_batches.fields.original_filename')),
                        TextEntry::make('total_rows')
                            ->label(__('import_batches.fields.total_rows')),
                        TextEntry::make('success_count')
                            ->label(__('import_batches.fields.success_count')),
                        TextEntry::make('error_count')
                            ->label(__('import_batches.fields.error_count')),
                        TextEntry::make('failure_reason')
                            ->label(__('import_batches.fields.failure_reason'))
                            ->visible(fn (ImportBatch $record): bool => $record->status === ImportBatchStatus::Failed)
                            ->columnSpanFull(),
                        TextEntry::make('started_at')
                            ->label(__('import_batches.fields.started_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('finished_at')
                            ->label(__('import_batches.fields.finished_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('creator.name')
                            ->label(__('common.fields.created_by')),
                    ]),
            ]);
    }
}
