<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\Branch;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('users.sections.user_info'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('users.fields.name'))
                            ->required()
                            ->maxLength(150),

                        TextInput::make('email')
                            ->label(__('users.fields.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('deleted_at')),

                        TextInput::make('password')
                            ->label(__('users.fields.password'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),

                        Select::make('role')
                            ->label(__('users.fields.role'))
                            ->options(UserRole::class)
                            ->default(UserRole::Cliente)
                            ->native(false)
                            ->required()
                            ->live(),

                        Toggle::make('can_approve')
                            ->label(__('users.fields.can_approve'))
                            ->default(false)
                            ->visible(fn (Get $get): bool => in_array(
                                $get('role'),
                                [UserRole::Operador->value, UserRole::Adm->value, UserRole::Operador, UserRole::Adm],
                                true,
                            )),

                        Toggle::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(__('users.sections.branches'))
                    ->schema([
                        Select::make('branches')
                            ->label(__('users.fields.branches'))
                            ->multiple()
                            ->options(fn (): array => Branch::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live(),

                        Select::make('default_branch')
                            ->label(__('users.fields.default_branch'))
                            ->options(fn (Get $get): array => Branch::query()
                                ->whereIn('id', $get('branches') ?? [])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                    ])
                    ->columns(2),
            ]);
    }
}
