<?php

declare(strict_types=1);

namespace App\Filament\Resources\CostCenters;

use App\Filament\Resources\CostCenters\Pages\CreateCostCenter;
use App\Filament\Resources\CostCenters\Pages\EditCostCenter;
use App\Filament\Resources\CostCenters\Pages\ListCostCenters;
use App\Filament\Resources\CostCenters\Pages\ViewCostCenter;
use App\Filament\Resources\CostCenters\Schemas\CostCenterForm;
use App\Filament\Resources\CostCenters\Schemas\CostCenterInfolist;
use App\Filament\Resources\CostCenters\Tables\CostCentersTable;
use App\Models\CostCenter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('cost_centers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cost_centers.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.registrations');
    }

    public static function form(Schema $schema): Schema
    {
        return CostCenterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CostCenterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCentersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCenters::route('/'),
            'create' => CreateCostCenter::route('/create'),
            'view' => ViewCostCenter::route('/{record}'),
            'edit' => EditCostCenter::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
