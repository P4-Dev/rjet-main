<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Schemas;

use App\Enums\AttachmentBatchStatus;
use App\Models\AttachmentBatch;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AttachmentBatchInfolist
{
    public const POLLING_INTERVAL = '5s';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('attachment_batches.sections.status'))
                    ->key('status')
                    ->poll(fn (AttachmentBatch $record): ?string => $record->isTerminal() ? null : self::POLLING_INTERVAL)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('attachment_batches.fields.status'))
                            ->badge(),
                        TextEntry::make('items_count')
                            ->label(__('attachment_batches.fields.items_count')),
                        TextEntry::make('classified_count')
                            ->label(__('attachment_batches.fields.classified_count')),
                        TextEntry::make('renamed_count')
                            ->label(__('attachment_batches.fields.renamed_count')),
                        TextEntry::make('failed_count')
                            ->label(__('attachment_batches.fields.failed_count')),
                        TextEntry::make('failure_reason')
                            ->label(__('attachment_batches.fields.failure_reason'))
                            ->visible(fn (AttachmentBatch $record): bool => in_array(
                                $record->status,
                                [AttachmentBatchStatus::Failed, AttachmentBatchStatus::PartiallyFailed],
                                true,
                            ))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('classified_at')
                            ->label(__('attachment_batches.fields.classified_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('renaming_started_at')
                            ->label(__('attachment_batches.fields.renaming_started_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('renamed_at')
                            ->label(__('attachment_batches.fields.renamed_at'))
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('naming_generated_at')
                            ->label(__('attachment_batches.fields.naming_generated_at'))
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('creator.name')
                            ->label(__('common.fields.created_by'))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
