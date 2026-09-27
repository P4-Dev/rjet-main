<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Tables;

use App\DTOs\EligiblePaymentFilterData;
use App\Enums\PaymentMethod;
use App\Filament\Resources\PaymentSettlements\Actions\CreatePaymentSettlementBulkAction;
use App\Services\PaymentSettlementService;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class EligiblePaymentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(PaymentSettlementService::class)->eligibleQuery(
                new EligiblePaymentFilterData,
                Filament::auth()->user(),
            ))
            ->defaultSort('due_date', 'asc')
            ->emptyStateHeading(__('payment_settlements.messages.no_eligible'))
            ->columns([
                TextColumn::make('branch.name')
                    ->label(__('payment_settlements.fields.branch'))
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(__('payment_requests.fields.supplier_id'))
                    ->searchable(),
                TextColumn::make('payment_method')
                    ->label(__('payment_requests.fields.payment_method'))
                    ->badge(),
                TextColumn::make('bankDetails.deposit_type')
                    ->label(__('payment_settlements.fields.deposit_type'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label(__('payment_requests.fields.due_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('net_amount')
                    ->label(__('payment_requests.fields.net_amount'))
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make()->money('BRL')->label(__('payment_settlements.fields.total_amount'))),
                TextColumn::make('status')
                    ->label(__('payment_settlements.fields.status'))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('branch')
                    ->label(__('payment_settlements.filters.branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('due_date')
                    ->schema([
                        DatePicker::make('due_from')
                            ->label(__('payment_settlements.filters.due_from')),
                        DatePicker::make('due_until')
                            ->label(__('payment_settlements.filters.due_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->dueBetween($data['due_from'] ?? null, $data['due_until'] ?? null))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['due_from'] ?? null)) {
                            $indicators[] = Indicator::make(__('payment_settlements.filters.due_from').': '.Carbon::parse($data['due_from'])->format('d/m/Y'))
                                ->removeField('due_from');
                        }

                        if (filled($data['due_until'] ?? null)) {
                            $indicators[] = Indicator::make(__('payment_settlements.filters.due_until').': '.Carbon::parse($data['due_until'])->format('d/m/Y'))
                                ->removeField('due_until');
                        }

                        return $indicators;
                    }),
                SelectFilter::make('payment_method')
                    ->label(__('payment_requests.fields.payment_method'))
                    ->options(PaymentMethod::class),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    CreatePaymentSettlementBulkAction::make(),
                ]),
            ]);
    }
}
