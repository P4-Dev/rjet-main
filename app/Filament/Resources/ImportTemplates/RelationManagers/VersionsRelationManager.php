<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                TextColumn::make('version')
                    ->label(__('import_templates.fields.version'))
                    ->sortable(),
                IconColumn::make('is_current')
                    ->label(__('import_templates.fields.is_current'))
                    ->boolean(),
                TextColumn::make('published_at')
                    ->label(__('import_templates.fields.published_at'))
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('creator.name')
                    ->label(__('common.fields.created_by')),
                TextColumn::make('mappings_count')
                    ->counts('mappings')
                    ->label(__('import_templates.fields.mappings')),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('version', 'desc');
    }
}
