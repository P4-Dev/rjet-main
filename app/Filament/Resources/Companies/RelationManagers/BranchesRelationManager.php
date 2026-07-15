<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Filament\Resources\Branches\Actions\DeleteBranchAction;
use App\Rules\ValidCnpj;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
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

final class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('branches.fields.name'))
                    ->required()
                    ->maxLength(150),

                TextInput::make('legal_name')
                    ->label(__('branches.fields.legal_name'))
                    ->required()
                    ->maxLength(200),

                TextInput::make('document')
                    ->label(__('branches.fields.document'))
                    ->required()
                    ->maxLength(20)
                    ->rule(new ValidCnpj)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),

                Toggle::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('branches.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('document')
                    ->label(__('branches.fields.document'))
                    ->searchable(),

                TextColumn::make('bank_accounts_count')
                    ->label(__('branches.fields.bank_accounts_count'))
                    ->counts('bankAccounts')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteBranchAction::make(),
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
                ->withCount('bankAccounts')
                ->withoutGlobalScopes([SoftDeletingScope::class]));
    }
}
