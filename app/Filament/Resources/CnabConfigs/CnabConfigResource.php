<?php

declare(strict_types=1);

namespace App\Filament\Resources\CnabConfigs;

use App\Filament\Resources\CnabConfigs\Pages\CreateCnabConfig;
use App\Filament\Resources\CnabConfigs\Pages\EditCnabConfig;
use App\Filament\Resources\CnabConfigs\Pages\ListCnabConfigs;
use App\Filament\Resources\CnabConfigs\Pages\ViewCnabConfig;
use App\Filament\Resources\CnabConfigs\RelationManagers\CnabFilesRelationManager;
use App\Filament\Resources\CnabConfigs\Schemas\CnabConfigForm;
use App\Filament\Resources\CnabConfigs\Schemas\CnabConfigInfolist;
use App\Filament\Resources\CnabConfigs\Tables\CnabConfigsTable;
use App\Models\CnabConfig;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class CnabConfigResource extends Resource
{
    protected static ?string $model = CnabConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('cnab_configs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cnab_configs.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('cnab_configs.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', CnabConfig::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CnabConfigForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CnabConfigInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CnabConfigsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CnabFilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCnabConfigs::route('/'),
            'create' => CreateCnabConfig::route('/create'),
            'view' => ViewCnabConfig::route('/{record}'),
            'edit' => EditCnabConfig::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['branchBankAccount.branch', 'branchBankAccount.bank', 'updater']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['branchBankAccount.branch', 'branchBankAccount.bank', 'creator', 'updater']);
    }
}
