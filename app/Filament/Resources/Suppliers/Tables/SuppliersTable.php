<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Tables;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
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

final class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('companyPaymentMethods'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('suppliers.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('document')
                    ->label(__('suppliers.fields.document'))
                    ->searchable(),

                TextColumn::make('person_type')
                    ->label(__('suppliers.fields.person_type'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('default_payment_method')
                    ->label(__('suppliers.fields.default_payment_method'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('company_payment_methods_count')
                    ->label(__('suppliers.fields.company_payment_methods_count'))
                    ->counts('companyPaymentMethods')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('person_type')
                    ->label(__('suppliers.fields.person_type'))
                    ->options(PersonType::class),

                SelectFilter::make('default_payment_method')
                    ->label(__('suppliers.fields.default_payment_method'))
                    ->options(PaymentMethod::class),

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
