<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Tables;

use App\Enums\AnalyticalReportStatus;
use App\Filament\Resources\AnalyticalReports\Actions\DownloadAnalyticalReportAction;
use App\Filament\Resources\AnalyticalReports\Actions\RetryAnalyticalReportAction;
use App\Models\AnalyticalReport;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AnalyticalReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'branch', 'creator']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label(__('common.fields.created_by'))
                    ->searchable(),
                TextColumn::make('date_basis')
                    ->label(__('analytical_reports.fields.date_basis'))
                    ->badge(),
                TextColumn::make('period')
                    ->label(__('analytical_reports.fields.period'))
                    ->state(fn (AnalyticalReport $record): string => sprintf(
                        '%s – %s',
                        $record->period_start->format('d/m/Y'),
                        $record->period_end->format('d/m/Y'),
                    )),
                TextColumn::make('company.name')
                    ->label(__('analytical_reports.fields.company_id'))
                    ->placeholder(__('analytical_reports.fields.all'))
                    ->toggleable(),
                TextColumn::make('branch.name')
                    ->label(__('analytical_reports.fields.branch_id'))
                    ->placeholder(__('analytical_reports.fields.all'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('analytical_reports.fields.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('rows_count')
                    ->label(__('analytical_reports.fields.rows_count'))
                    ->numeric(),
                TextColumn::make('generated_at')
                    ->label(__('analytical_reports.fields.generated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('analytical_reports.filters.status'))
                    ->options(AnalyticalReportStatus::class),
                Filter::make('mine')
                    ->label(__('analytical_reports.filters.mine'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('created_by', Filament::auth()->id())),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ViewAction::make(),
                DownloadAnalyticalReportAction::make(),
                RetryAnalyticalReportAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll(fn (): ?string => AnalyticalReport::query()->inProgress()->exists() ? '5s' : null);
    }
}
