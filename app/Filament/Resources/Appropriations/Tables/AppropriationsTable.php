<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
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

final class AppropriationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('company'))
            ->columns([
                TextColumn::make('company.name')
                    ->label(__('appropriations.fields.company'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('code')
                    ->label(__('appropriations.fields.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('appropriations.fields.name'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label(__('appropriations.fields.company'))
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
            ->defaultSort('name');
    }
}
