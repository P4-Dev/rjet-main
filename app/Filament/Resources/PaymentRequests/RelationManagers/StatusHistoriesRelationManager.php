<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('common.sections.history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('changedBy'))
            ->columns([
                TextColumn::make('from_status')
                    ->label(__('payment_request_status_history.fields.from_status'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('to_status')
                    ->label(__('payment_request_status_history.fields.to_status'))
                    ->badge(),
                TextColumn::make('changedBy.name')
                    ->label(__('payment_request_status_history.fields.changed_by'))
                    ->placeholder('—'),
                TextColumn::make('notes')
                    ->label(__('payment_request_status_history.fields.notes'))
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->notes)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('payment_request_status_history.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
