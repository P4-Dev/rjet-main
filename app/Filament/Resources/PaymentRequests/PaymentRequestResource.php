<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests;

use App\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\EditPaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\PaymentRequests\RelationManagers\ApprovalsRelationManager;
use App\Filament\Resources\PaymentRequests\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\PaymentRequests\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\PaymentRequests\Schemas\PaymentRequestForm;
use App\Filament\Resources\PaymentRequests\Schemas\PaymentRequestInfolist;
use App\Filament\Resources\PaymentRequests\Tables\PaymentRequestsTable;
use App\Models\PaymentRequest;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('payment_requests.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment_requests.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttachmentsRelationManager::class,
            StatusHistoriesRelationManager::class,
            ApprovalsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentRequests::route('/'),
            'create' => CreatePaymentRequest::route('/create'),
            'view' => ViewPaymentRequest::route('/{record}'),
            'edit' => EditPaymentRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        return $user !== null ? $query->visibleTo($user) : $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['branch.company', 'supplier', 'costCenter', 'appropriation', 'bankDetails.bank', 'approvals.approver']);
    }
}
