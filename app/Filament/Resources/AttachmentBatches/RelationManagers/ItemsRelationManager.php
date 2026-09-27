<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\RelationManagers;

use App\Enums\AttachmentBatchItemStatus;
use App\Filament\Resources\AttachmentBatches\Actions\DownloadAttachmentBatchItemAction;
use App\Models\AttachmentBatchItem;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('attachment_batches.sections.items');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sort_order')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['attachment.attachable', 'paymentRequest', 'supplier']))
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('attachment_batches.fields.sort_order'))
                    ->formatStateUsing(fn (int $state): int => $state + 1)
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label(__('attachment_batches.fields.display_name'))
                    ->state(fn (AttachmentBatchItem $record): string => $record->attachment?->displayName() ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'attachment',
                        fn (Builder $attachmentQuery): Builder => $attachmentQuery
                            ->where('original_name', 'like', "%{$search}%")
                            ->orWhere('standardized_name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('attachment.mime_type')
                    ->label(__('attachment_batches.fields.mime_type'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('attachment_batches.fields.status'))
                    ->badge(),
                TextColumn::make('destination_type')
                    ->label(__('attachment_batches.fields.destination_type'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('paymentRequest.id')
                    ->label(__('attachment_batches.fields.payment_request_id'))
                    ->limit(8)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('supplier.name')
                    ->label(__('attachment_batches.fields.supplier_id'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('operational_label')
                    ->label(__('attachment_batches.fields.operational_label'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('classified_at')
                    ->label(__('attachment_batches.fields.classified_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rename_error')
                    ->label(__('attachment_batches.fields.rename_error'))
                    ->visible(fn (): bool => $this->getOwnerRecord()->getAttribute('failed_count') > 0)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->headerActions([])
            ->recordActions([
                DownloadAttachmentBatchItemAction::make(),
            ])
            ->toolbarActions([])
            ->recordClasses(fn (AttachmentBatchItem $record): ?string => $record->status === AttachmentBatchItemStatus::Failed ? 'bg-danger-50 dark:bg-danger-950' : null)
            ->defaultSort('sort_order');
    }
}
