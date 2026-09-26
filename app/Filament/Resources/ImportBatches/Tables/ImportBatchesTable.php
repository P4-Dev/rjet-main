<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Enums\ImportBatchStatus;
use App\Filament\Resources\ImportBatches\Actions\DownloadImportSpreadsheetAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['templateVersion.template', 'creator']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('import_batches.fields.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('templateVersion.template.name')
                    ->label(__('import_batches.fields.template')),
                TextColumn::make('success_count')
                    ->label(__('import_batches.fields.success_count'))
                    ->sortable(),
                TextColumn::make('error_count')
                    ->label(__('import_batches.fields.error_count'))
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label(__('common.fields.created_by'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('import_batches.fields.status'))
                    ->options(ImportBatchStatus::class),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')
                            ->label(__('payment_requests.filters.created_from')),
                        DatePicker::make('created_until')
                            ->label(__('payment_requests.filters.created_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                DownloadImportSpreadsheetAction::make(),
                DeleteAction::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
