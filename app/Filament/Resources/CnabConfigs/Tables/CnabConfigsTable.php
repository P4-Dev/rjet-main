<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs\Tables;

use App\Enums\CnabLayout;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\CnabConfig;
use App\Services\CnabConfigService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CnabConfigsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branchBankAccount.branch', 'updater']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('branchBankAccount.branch.name')
                    ->label(__('cnab_configs.fields.branch'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branchBankAccount.bank_code')
                    ->label(__('cnab_configs.fields.bank_code')),
                TextColumn::make('account')
                    ->label(__('cnab_configs.fields.account'))
                    ->state(fn (CnabConfig $record): ?string => $record->branchBankAccount !== null
                        ? __('cnab_configs.formats.agency_account', [
                            'agency' => $record->branchBankAccount->agency,
                            'account' => $record->branchBankAccount->account_number,
                            'digit' => $record->branchBankAccount->account_digit,
                        ])
                        : null),
                TextColumn::make('layout')
                    ->label(__('cnab_configs.fields.layout'))
                    ->badge(),
                TextColumn::make('last_file_sequence')
                    ->label(__('cnab_configs.fields.last_file_sequence')),
                IconColumn::make('is_active')
                    ->label(__('cnab_configs.fields.is_active'))
                    ->boolean(),
                TextColumn::make('updater.name')
                    ->label(__('common.fields.updated_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('common.fields.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch')
                    ->label(__('cnab_configs.filters.branch'))
                    ->options(fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('branchBankAccount', fn (Builder $account): Builder => $account->where('branch_id', $data['value']))
                        : $query),
                SelectFilter::make('layout')
                    ->label(__('cnab_configs.fields.layout'))
                    ->options(CnabLayout::class),
                TernaryFilter::make('is_active')
                    ->label(__('cnab_configs.fields.is_active')),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('cnab_configs.messages.delete_replaces'))
                    ->using(fn (CnabConfig $record, Action $action): bool => self::runGuarded(
                        $action,
                        fn () => app(CnabConfigService::class)->delete($record, Filament::auth()->user()),
                    )),
                RestoreAction::make()
                    ->using(fn (CnabConfig $record, Action $action): bool => self::runGuarded(
                        $action,
                        fn () => app(CnabConfigService::class)->restore($record, Filament::auth()->user()),
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records, Action $action): bool => self::runGuarded(
                            $action,
                            fn () => $records->each(fn (CnabConfig $record) => app(CnabConfigService::class)->delete($record, Filament::auth()->user())),
                        )),
                ]),
            ]);
    }

    private static function runGuarded(Action $action, callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (BusinessException $exception) {
            Notification::make()
                ->title($exception->getUserMessage())
                ->danger()
                ->send();

            $action->halt();

            return false;
        }
    }
}
