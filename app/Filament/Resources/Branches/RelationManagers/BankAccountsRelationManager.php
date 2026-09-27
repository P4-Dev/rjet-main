<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Enums\AccountType;
use App\Exceptions\BranchException;
use App\Exceptions\BusinessException;
use App\Models\Bank;
use App\Models\BranchBankAccount;
use App\Services\BranchBankAccountService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

final class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bank_id')
                    ->label(__('branch_bank_accounts.fields.bank_id'))
                    ->relationship('bank', 'name')
                    ->getOptionLabelFromRecordUsing(
                        fn (Bank $record): string => "{$record->code} — {$record->name}"
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $bank = Bank::query()->find($state);

                        if ($bank === null) {
                            return;
                        }

                        $set('bank_code', $bank->code);
                        $set('bank_name', $bank->name);
                    }),

                TextInput::make('bank_code')
                    ->label(__('branch_bank_accounts.fields.bank_code'))
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(3),

                TextInput::make('bank_name')
                    ->label(__('branch_bank_accounts.fields.bank_name'))
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),

                TextInput::make('agency')
                    ->label(__('branch_bank_accounts.fields.agency'))
                    ->required()
                    ->maxLength(10),

                TextInput::make('agency_digit')
                    ->label(__('branch_bank_accounts.fields.agency_digit'))
                    ->maxLength(2),

                TextInput::make('account_number')
                    ->label(__('branch_bank_accounts.fields.account_number'))
                    ->required()
                    ->maxLength(20),

                TextInput::make('account_digit')
                    ->label(__('branch_bank_accounts.fields.account_digit'))
                    ->maxLength(2),

                Select::make('account_type')
                    ->label(__('branch_bank_accounts.fields.account_type'))
                    ->options(AccountType::class)
                    ->native(false),

                TextInput::make('holder_name')
                    ->label(__('branch_bank_accounts.fields.holder_name'))
                    ->maxLength(255),

                Toggle::make('is_default')
                    ->label(__('common.fields.is_default'))
                    ->default(false),

                Toggle::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bank_name')
            ->columns([
                TextColumn::make('bank.name')
                    ->label(__('branch_bank_accounts.fields.bank_id'))
                    ->placeholder(fn ($record) => $record->bank_name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('bank_code')
                    ->label(__('branch_bank_accounts.fields.bank_code'))
                    ->searchable(),

                TextColumn::make('agency')
                    ->label(__('branch_bank_accounts.fields.agency')),

                TextColumn::make('account_number')
                    ->label(__('branch_bank_accounts.fields.account_number')),

                TextColumn::make('account_type')
                    ->label(__('branch_bank_accounts.fields.account_type'))
                    ->badge(),

                IconColumn::make('is_default')
                    ->label(__('common.fields.is_default'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->before(function (BranchBankAccount $record, DeleteAction $action): void {
                            try {
                                app(BranchBankAccountService::class)->ensureDeletable($record);
                            } catch (BranchException $exception) {
                                Notification::make()
                                    ->title($exception->getUserMessage())
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records, Action $action): bool => self::runGuarded(
                            $action,
                            function () use ($records): void {
                                $service = app(BranchBankAccountService::class);

                                $records->each(fn (BranchBankAccount $record) => $service->ensureDeletable($record));

                                DB::transaction(fn () => $records->each(fn (BranchBankAccount $record) => $service->delete($record)));
                            },
                        )),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('bank')
                ->withoutGlobalScopes([SoftDeletingScope::class]));
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
