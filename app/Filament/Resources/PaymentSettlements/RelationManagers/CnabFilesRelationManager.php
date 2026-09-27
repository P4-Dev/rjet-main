<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\RelationManagers;

use App\Filament\Resources\PaymentSettlements\Actions\DownloadCnabFileAction;
use App\Filament\Resources\PaymentSettlements\Actions\RegenerateCnabFileAction;
use App\Filament\Resources\PaymentSettlements\Actions\RetryCnabFileGenerationAction;
use App\Filament\Resources\PaymentSettlements\Actions\ViewCnabValidationErrorsAction;
use App\Models\CnabFile;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CnabFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'cnabFiles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('payment_settlements.sections.cnab_files');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_sequence')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['creator', 'settlement']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('file_sequence')
                    ->label(__('cnab_files.fields.file_sequence'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('cnab_files.fields.status'))
                    ->badge(),
                TextColumn::make('items_count')
                    ->label(__('cnab_files.fields.items_count')),
                TextColumn::make('total_amount')
                    ->label(__('cnab_files.fields.total_amount'))
                    ->money('BRL'),
                TextColumn::make('creator.name')
                    ->label(__('cnab_files.fields.requested_by'))
                    ->placeholder('—'),
                TextColumn::make('generated_at')
                    ->label(__('cnab_files.fields.generated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('downloaded_at')
                    ->label(__('cnab_files.fields.downloaded_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('failure_reason')
                    ->label(__('cnab_files.fields.failure_reason'))
                    ->limit(60)
                    ->tooltip(fn (CnabFile $record): ?string => $record->failure_reason)
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                DownloadCnabFileAction::make(),
                RetryCnabFileGenerationAction::make(),
                RegenerateCnabFileAction::make(),
                ViewCnabValidationErrorsAction::make(),
            ])
            ->toolbarActions([]);
    }
}
