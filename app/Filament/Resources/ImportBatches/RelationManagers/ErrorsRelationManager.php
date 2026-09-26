<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ErrorsRelationManager extends RelationManager
{
    protected static string $relationship = 'errors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('import_batches.sections.errors');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('row_number')
            ->columns([
                TextColumn::make('row_number')
                    ->label(__('import_batches.fields.row_number'))
                    ->sortable(),
                TextColumn::make('target_field')
                    ->label(__('import_batches.fields.target_field'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('message')
                    ->label(__('import_batches.fields.message'))
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('row_number');
    }
}
