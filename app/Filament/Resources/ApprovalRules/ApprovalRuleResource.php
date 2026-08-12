<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules;

use App\Filament\Resources\ApprovalRules\Pages\CreateApprovalRule;
use App\Filament\Resources\ApprovalRules\Pages\EditApprovalRule;
use App\Filament\Resources\ApprovalRules\Pages\ListApprovalRules;
use App\Filament\Resources\ApprovalRules\Pages\ViewApprovalRule;
use App\Filament\Resources\ApprovalRules\Schemas\ApprovalRuleForm;
use App\Filament\Resources\ApprovalRules\Schemas\ApprovalRuleInfolist;
use App\Filament\Resources\ApprovalRules\Tables\ApprovalRulesTable;
use App\Models\ApprovalRule;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ApprovalRuleResource extends Resource
{
    protected static ?string $model = ApprovalRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('approval_rules.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('approval_rules.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('approval_rules.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can('viewAny', ApprovalRule::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ApprovalRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApprovalRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalRules::route('/'),
            'create' => CreateApprovalRule::route('/create'),
            'view' => ViewApprovalRule::route('/{record}'),
            'edit' => EditApprovalRule::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['branch.company', 'approver']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['branch.company', 'approver']);
    }
}
