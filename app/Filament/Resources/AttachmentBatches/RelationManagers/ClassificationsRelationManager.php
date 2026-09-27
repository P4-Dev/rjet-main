<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ClassificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'classifications';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('attachment_batches.sections.classifications');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('classified_at')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'attachmentBatchItem', 'paymentRequest', 'supplier']))
            ->columns([
                TextColumn::make('classified_at')
                    ->label(__('attachment_batches.fields.classified_at'))
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        'attachment_batch_item_classifications.classified_at',
                        $direction,
                    )),
                TextColumn::make('user.name')
                    ->label(__('attachment_batches.fields.classified_by'))
                    ->placeholder('—'),
                TextColumn::make('attachmentBatchItem.sort_order')
                    ->label(__('attachment_batches.fields.sort_order'))
                    ->formatStateUsing(fn (int $state): int => $state + 1),
                TextColumn::make('destination_type')
                    ->label(__('attachment_batches.fields.destination_type'))
                    ->badge(),
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
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('attachment_batch_item_classifications.classified_at'));
    }
}
