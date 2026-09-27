<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\RelationManagers;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Filament\Resources\PaymentSettlements\Actions\ReleasePaymentSettlementItemAction;
use App\Models\PaymentSettlementItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('payment_settlements.sections.items');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['paymentRequest.supplier', 'paymentRequest.bankDetails.bank']))
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('paymentRequest.supplier.name')
                    ->label(__('payment_requests.fields.supplier_id'))
                    ->searchable(),
                TextColumn::make('paymentRequest.payment_method')
                    ->label(__('payment_requests.fields.payment_method'))
                    ->badge(),
                TextColumn::make('paymentRequest.due_date')
                    ->label(__('payment_requests.fields.due_date'))
                    ->date('d/m/Y'),
                TextColumn::make('amount')
                    ->label(__('payment_settlements.fields.amount'))
                    ->money('BRL')
                    ->summarize(Sum::make()->money('BRL')),
                TextColumn::make('paymentRequest.status')
                    ->label(__('payment_settlements.fields.request_status'))
                    ->badge(),
                TextColumn::make('released_at')
                    ->label(__('payment_settlements.fields.released_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('payment_settlements.messages.item_active')),
                TextColumn::make('open_request')
                    ->label('')
                    ->state(__('payment_settlements.actions.open_request'))
                    ->url(fn (PaymentSettlementItem $record): string => PaymentRequestResource::getUrl('view', ['record' => $record->payment_request_id])),
            ])
            ->filters([
                TernaryFilter::make('released')
                    ->label(__('payment_settlements.filters.released'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('released_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('released_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->headerActions([])
            ->recordActions([
                ReleasePaymentSettlementItemAction::make(),
            ])
            ->toolbarActions([]);
    }
}
