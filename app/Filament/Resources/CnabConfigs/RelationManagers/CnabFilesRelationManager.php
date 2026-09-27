<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\RelationManagers;

use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Models\CnabFile;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CnabFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'cnabFiles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('cnab_files.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_sequence')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['settlement']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('file_sequence')
                    ->label(__('cnab_files.fields.file_sequence'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('cnab_files.fields.status'))
                    ->badge(),
                TextColumn::make('settlement.settlement_date')
                    ->label(__('payment_settlements.fields.settlement_date'))
                    ->date('d/m/Y')
                    ->url(fn (CnabFile $record): string => PaymentSettlementResource::getUrl('view', ['record' => $record->payment_settlement_id])),
                TextColumn::make('items_count')
                    ->label(__('cnab_files.fields.items_count')),
                TextColumn::make('total_amount')
                    ->label(__('cnab_files.fields.total_amount'))
                    ->money('BRL'),
                TextColumn::make('generated_at')
                    ->label(__('cnab_files.fields.generated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
