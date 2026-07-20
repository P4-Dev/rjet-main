<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\PaymentMethod;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class CompanyPaymentMethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'companyPaymentMethods';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->label(__('supplier_company_payment_methods.fields.company_id'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, $get, $livewire) => $rule
                            ->where('supplier_id', $livewire->getOwnerRecord()->getKey())
                            ->whereNull('deleted_at'),
                    ),

                Select::make('payment_method')
                    ->label(__('supplier_company_payment_methods.fields.payment_method'))
                    ->options(PaymentMethod::class)
                    ->required()
                    ->native(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_method')
            ->columns([
                TextColumn::make('company.name')
                    ->label(__('supplier_company_payment_methods.fields.company'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label(__('supplier_company_payment_methods.fields.payment_method'))
                    ->badge(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('company')
                ->withoutGlobalScopes([SoftDeletingScope::class]));
    }
}
