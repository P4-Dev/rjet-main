<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appropriations;

use App\Filament\Resources\Appropriations\Pages\CreateAppropriation;
use App\Filament\Resources\Appropriations\Pages\EditAppropriation;
use App\Filament\Resources\Appropriations\Pages\ListAppropriations;
use App\Filament\Resources\Appropriations\Pages\ViewAppropriation;
use App\Filament\Resources\Appropriations\Schemas\AppropriationForm;
use App\Filament\Resources\Appropriations\Schemas\AppropriationInfolist;
use App\Filament\Resources\Appropriations\Tables\AppropriationsTable;
use App\Models\Appropriation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class AppropriationResource extends Resource
{
    protected static ?string $model = Appropriation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('appropriations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('appropriations.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.registrations');
    }

    public static function form(Schema $schema): Schema
    {
        return AppropriationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppropriationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppropriationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppropriations::route('/'),
            'create' => CreateAppropriation::route('/create'),
            'view' => ViewAppropriation::route('/{record}'),
            'edit' => EditAppropriation::route('/{record}/edit'),
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
