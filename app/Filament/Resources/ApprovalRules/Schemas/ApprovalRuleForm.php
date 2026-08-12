<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApprovalRules\Schemas;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class ApprovalRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('approval_rules.sections.rule'))
                    ->columns(2)
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('approval_rules.fields.branch_id'))
                            ->relationship(
                                name: 'branch',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->with('company')->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Branch $branch): string => "{$branch->company?->name} / {$branch->name}"
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),

                        TextInput::make('min_amount')
                            ->label(__('approval_rules.fields.min_amount'))
                            ->numeric()
                            ->prefix('R$')
                            ->required()
                            ->minValue(0)
                            ->step(0.01)
                            ->live(onBlur: true),

                        TextInput::make('max_amount')
                            ->label(__('approval_rules.fields.max_amount'))
                            ->numeric()
                            ->prefix('R$')
                            ->minValue(0)
                            ->step(0.01)
                            ->helperText(__('approval_rules.hints.max_amount_null'))
                            ->nullable(),

                        Select::make('approver_user_id')
                            ->label(__('approval_rules.fields.approver_user_id'))
                            ->options(fn (): array => User::query()
                                ->approvers()
                                ->active()
                                ->whereIn('role', [UserRole::Operador, UserRole::Adm])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ]),
            ]);
    }
}
