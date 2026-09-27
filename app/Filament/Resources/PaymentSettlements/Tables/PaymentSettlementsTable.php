<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Tables;

use App\Enums\PaymentSettlementStatus;
use App\Exceptions\BusinessException;
use App\Models\PaymentSettlement;
use App\Services\PaymentSettlementService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class PaymentSettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'branchBankAccount', 'currentCnabFile', 'creator']))
            ->defaultSort('settlement_date', 'desc')
            ->columns([
                TextColumn::make('settlement_date')
                    ->label(__('payment_settlements.fields.settlement_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label(__('payment_settlements.fields.branch'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branchBankAccount.bank_code')
                    ->label(__('payment_settlements.fields.bank_code')),
                TextColumn::make('status')
                    ->label(__('payment_settlements.fields.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('payment_settlements.fields.items_count')),
                TextColumn::make('total_amount')
                    ->label(__('payment_settlements.fields.total_amount'))
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('currentCnabFile.status')
                    ->label(__('payment_settlements.fields.cnab_status'))
                    ->badge()
                    ->placeholder(__('payment_settlements.messages.no_cnab_short')),
                TextColumn::make('creator.name')
                    ->label(__('common.fields.created_by'))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payment_settlements.fields.status'))
                    ->options(PaymentSettlementStatus::class),
                SelectFilter::make('branch')
                    ->label(__('payment_settlements.filters.branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('settlement_date')
                    ->schema([
                        DatePicker::make('settlement_from')
                            ->label(__('payment_settlements.filters.settlement_from')),
                        DatePicker::make('settlement_until')
                            ->label(__('payment_settlements.filters.settlement_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['settlement_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('settlement_date', '>=', $date))
                        ->when($data['settlement_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('settlement_date', '<=', $date))),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->using(function (PaymentSettlement $record, DeleteAction $action): bool {
                        try {
                            app(PaymentSettlementService::class)->delete($record, Filament::auth()->user());

                            return true;
                        } catch (BusinessException $exception) {
                            Notification::make()
                                ->title($exception->getUserMessage())
                                ->danger()
                                ->send();

                            $action->halt();

                            return false;
                        }
                    }),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records): mixed => $records
                            ->filter(fn (PaymentSettlement $record): bool => Filament::auth()->user()?->can('delete', $record) ?? false)
                            ->each(fn (PaymentSettlement $record) => app(PaymentSettlementService::class)->delete($record, Filament::auth()->user()))),
                ]),
            ]);
    }
}
