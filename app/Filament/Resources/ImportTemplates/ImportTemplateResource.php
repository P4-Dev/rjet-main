<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates;

use App\Filament\Resources\ImportTemplates\Pages\CreateImportTemplate;
use App\Filament\Resources\ImportTemplates\Pages\EditImportTemplate;
use App\Filament\Resources\ImportTemplates\Pages\ListImportTemplates;
use App\Filament\Resources\ImportTemplates\Pages\ViewImportTemplate;
use App\Filament\Resources\ImportTemplates\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\ImportTemplates\Schemas\ImportTemplateForm;
use App\Filament\Resources\ImportTemplates\Schemas\ImportTemplateInfolist;
use App\Filament\Resources\ImportTemplates\Tables\ImportTemplatesTable;
use App\Models\ImportTemplate;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ImportTemplateResource extends Resource
{
    protected static ?string $model = ImportTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('import_templates.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('import_templates.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('import_templates.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', ImportTemplate::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ImportTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportTemplates::route('/'),
            'create' => CreateImportTemplate::route('/create'),
            'view' => ViewImportTemplate::route('/{record}'),
            'edit' => EditImportTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'branch', 'currentVersion']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['company', 'branch', 'currentVersion', 'versions.mappings', 'versions.creator']);
    }
}
