<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\CreateImportBatch;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\ImportBatches\RelationManagers\ErrorsRelationManager;
use App\Filament\Resources\ImportBatches\RelationManagers\PaymentRequestsRelationManager;
use App\Filament\Resources\ImportBatches\Schemas\ImportBatchForm;
use App\Filament\Resources\ImportBatches\Schemas\ImportBatchInfolist;
use App\Filament\Resources\ImportBatches\Tables\ImportBatchesTable;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $recordTitleAttribute = 'original_filename';

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('import_batches.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('import_batches.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('import_batches.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', ImportBatch::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ImportBatchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ErrorsRelationManager::class,
            PaymentRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'create' => CreateImportBatch::route('/create'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['templateVersion.template', 'creator']);
        $user = Filament::auth()->user();

        return $user !== null ? $query->visibleTo($user) : $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['templateVersion.template', 'creator']);
    }
}
