<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks\Tables;

use App\Filament\Resources\Banks\Actions\DeleteBankAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class BanksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('branchBankAccounts'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('banks.fields.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('banks.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ispb')
                    ->label(__('banks.fields.ispb'))
                    ->toggleable(),

                TextColumn::make('branch_bank_accounts_count')
                    ->label(__('banks.fields.branch_bank_accounts_count'))
                    ->counts('branchBankAccounts')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('common.fields.is_active')),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteBankAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }
}
