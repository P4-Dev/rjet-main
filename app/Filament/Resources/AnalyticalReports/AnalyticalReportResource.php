<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports;

use App\Filament\Resources\AnalyticalReports\Pages\ListAnalyticalReports;
use App\Filament\Resources\AnalyticalReports\Pages\ViewAnalyticalReport;
use App\Filament\Resources\AnalyticalReports\Schemas\AnalyticalReportForm;
use App\Filament\Resources\AnalyticalReports\Schemas\AnalyticalReportInfolist;
use App\Filament\Resources\AnalyticalReports\Tables\AnalyticalReportsTable;
use App\Models\AnalyticalReport;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class AnalyticalReportResource extends Resource
{
    protected static ?string $model = AnalyticalReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $recordTitleAttribute = 'filename';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('analytical_reports.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('analytical_reports.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('analytical_reports.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.reports');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', AnalyticalReport::class) ?? false;
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if ($record instanceof AnalyticalReport && blank($record->filename)) {
            return __('analytical_reports.label');
        }

        return parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return AnalyticalReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AnalyticalReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnalyticalReportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnalyticalReports::route('/'),
            'view' => ViewAnalyticalReport::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'branch', 'creator']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['company', 'branch', 'creator']);
    }
}
