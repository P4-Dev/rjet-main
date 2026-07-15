<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Tables;

use App\Filament\Resources\Branches\Actions\DeleteBranchAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('company')
                ->withCount('bankAccounts'))
            ->columns([
                TextColumn::make('company.name')
                    ->label(__('branches.fields.company_id'))
                    ->searchable()
                    ->sortable(),

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
                SelectFilter::make('company')
                    ->label(__('branches.filters.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label(__('common.fields.is_active')),
                TrashedFilter::make(),
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
            ->defaultSort('name');
    }
}
