<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Tables;

use App\Enums\AttachmentBatchStatus;
use App\Filament\Resources\AttachmentBatches\Actions\ClassifyAttachmentBatchAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AttachmentBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['creator']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('attachment_batches.fields.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('attachment_batches.fields.items_count'))
                    ->sortable(),
                TextColumn::make('classified_count')
                    ->label(__('attachment_batches.fields.classified_count'))
                    ->sortable(),
                TextColumn::make('renamed_count')
                    ->label(__('attachment_batches.fields.renamed_count'))
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label(__('attachment_batches.fields.failed_count'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label(__('common.fields.created_by'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('attachment_batches.fields.status'))
                    ->options(AttachmentBatchStatus::class),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')
                            ->label(__('payment_requests.filters.created_from')),
                        DatePicker::make('created_until')
                            ->label(__('payment_requests.filters.created_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                ClassifyAttachmentBatchAction::make(),
                DeleteAction::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
