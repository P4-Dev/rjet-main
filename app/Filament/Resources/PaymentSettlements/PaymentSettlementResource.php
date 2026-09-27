<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements;

use App\Filament\Resources\PaymentSettlements\Pages\CreatePaymentSettlement;
use App\Filament\Resources\PaymentSettlements\Pages\ListPaymentSettlements;
use App\Filament\Resources\PaymentSettlements\Pages\ViewPaymentSettlement;
use App\Filament\Resources\PaymentSettlements\RelationManagers\CnabFilesRelationManager;
use App\Filament\Resources\PaymentSettlements\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PaymentSettlements\Schemas\PaymentSettlementForm;
use App\Filament\Resources\PaymentSettlements\Schemas\PaymentSettlementInfolist;
use App\Filament\Resources\PaymentSettlements\Tables\PaymentSettlementsTable;
use App\Models\PaymentSettlement;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class PaymentSettlementResource extends Resource
{
    protected static ?string $model = PaymentSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('payment_settlements.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment_settlements.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment_settlements.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', PaymentSettlement::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentSettlementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentSettlementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentSettlementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            CnabFilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentSettlements::route('/'),
            'create' => CreatePaymentSettlement::route('/create'),
            'view' => ViewPaymentSettlement::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'branchBankAccount.bank', 'currentCnabFile', 'creator']);
        $user = Filament::auth()->user();

        return $user !== null ? $query->visibleTo($user) : $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['branch', 'branchBankAccount.bank', 'currentCnabFile', 'latestCnabFile', 'creator', 'settler', 'canceller']);
    }
}
