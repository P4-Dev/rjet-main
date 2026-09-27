<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches;

use App\Filament\Resources\AttachmentBatches\Pages\ClassifyAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\Pages\CreateAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\Pages\ListAttachmentBatches;
use App\Filament\Resources\AttachmentBatches\Pages\ViewAttachmentBatch;
use App\Filament\Resources\AttachmentBatches\RelationManagers\ClassificationsRelationManager;
use App\Filament\Resources\AttachmentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\AttachmentBatches\Schemas\AttachmentBatchForm;
use App\Filament\Resources\AttachmentBatches\Schemas\AttachmentBatchInfolist;
use App\Filament\Resources\AttachmentBatches\Tables\AttachmentBatchesTable;
use App\Models\AttachmentBatch;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class AttachmentBatchResource extends Resource
{
    protected static ?string $model = AttachmentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 16;

    public static function getModelLabel(): string
    {
        return __('attachment_batches.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('attachment_batches.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('attachment_batches.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', AttachmentBatch::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return AttachmentBatchForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AttachmentBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttachmentBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            ClassificationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttachmentBatches::route('/'),
            'create' => CreateAttachmentBatch::route('/create'),
            'view' => ViewAttachmentBatch::route('/{record}'),
            'classify' => ClassifyAttachmentBatch::route('/{record}/classify'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['creator']);
        $user = Filament::auth()->user();

        return $user !== null ? $query->visibleTo($user) : $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['creator', 'items.attachment', 'items.paymentRequest', 'items.supplier']);
    }
}
