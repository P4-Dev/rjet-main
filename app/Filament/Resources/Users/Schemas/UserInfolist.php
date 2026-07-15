<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('users.sections.user_info'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('users.fields.name')),

                        TextEntry::make('email')
                            ->label(__('users.fields.email')),

                        TextEntry::make('role')
                            ->label(__('users.fields.role'))
                            ->badge(),

                        IconEntry::make('can_approve')
                            ->label(__('users.fields.can_approve'))
                            ->boolean(),

                        IconEntry::make('is_active')
                            ->label(__('common.fields.is_active'))
                            ->boolean(),
                    ])
                    ->columns(2),

                Section::make(__('users.sections.branches'))
                    ->schema([
                        TextEntry::make('branches.name')
                            ->label(__('users.fields.branches'))
                            ->badge()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
