<?php

declare(strict_types=1);

namespace App\Filament\Resources\Banks;

use App\Filament\Resources\Banks\Pages\CreateBank;
use App\Filament\Resources\Banks\Pages\EditBank;
use App\Filament\Resources\Banks\Pages\ListBanks;
use App\Filament\Resources\Banks\Pages\ViewBank;
use App\Filament\Resources\Banks\Schemas\BankForm;
use App\Filament\Resources\Banks\Schemas\BankInfolist;
use App\Filament\Resources\Banks\Tables\BanksTable;
use App\Models\Bank;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BankResource extends Resource
{
    protected static ?string $model = Bank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('banks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('banks.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.registrations');
    }

    public static function form(Schema $schema): Schema
    {
        return BankForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BankInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BanksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanks::route('/'),
            'create' => CreateBank::route('/create'),
            'view' => ViewBank::route('/{record}'),
            'edit' => EditBank::route('/{record}/edit'),
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
