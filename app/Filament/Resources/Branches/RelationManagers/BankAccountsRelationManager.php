<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\RelationManagers;

use App\Enums\AccountType;
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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('bank_code')
                    ->label(__('branch_bank_accounts.fields.bank_code'))
                    ->required()
                    ->maxLength(3),

                TextInput::make('bank_name')
                    ->label(__('branch_bank_accounts.fields.bank_name'))
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
                TextColumn::make('bank_name')
                    ->label(__('branch_bank_accounts.fields.bank_name'))
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
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScopes([SoftDeletingScope::class]));
    }
}
