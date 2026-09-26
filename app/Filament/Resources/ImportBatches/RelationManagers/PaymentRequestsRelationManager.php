<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\RelationManagers;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PaymentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentRequests';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('import_batches.sections.payment_requests');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')
                    ->label(__('common.fields.id'))
                    ->limit(8),
                TextColumn::make('branch.name')
                    ->label(__('payment_requests.fields.branch_id')),
                TextColumn::make('supplier.name')
                    ->label(__('payment_requests.fields.supplier_id')),
                TextColumn::make('net_amount')
                    ->label(__('payment_requests.fields.net_amount'))
                    ->money('BRL'),
                TextColumn::make('status')
                    ->label(__('common.fields.status'))
                    ->badge(),
                TextColumn::make('due_date')
                    ->label(__('payment_requests.fields.due_date'))
                    ->date('d/m/Y'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => PaymentRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
