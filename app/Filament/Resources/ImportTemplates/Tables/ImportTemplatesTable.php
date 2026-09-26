<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Tables;

use App\Filament\Resources\ImportTemplates\Actions\PublishImportTemplateVersionAction;
use App\Services\ImportTemplateService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ImportTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'branch', 'currentVersion']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('import_templates.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label(__('import_templates.fields.company_id'))
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('branch.name')
                    ->label(__('import_templates.fields.branch_id'))
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('accepted_format')
                    ->label(__('import_templates.fields.accepted_format'))
                    ->badge(),
                TextColumn::make('currentVersion.version')
                    ->label(__('import_templates.fields.current_version')),
                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('common.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PublishImportTemplateVersionAction::make(),
                DeleteAction::make()
                    ->using(function ($record): void {
                        app(ImportTemplateService::class)->delete($record);
                    })
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('import_templates.messages.deleted')),
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
