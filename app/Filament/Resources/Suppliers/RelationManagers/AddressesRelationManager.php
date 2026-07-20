<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\AddressType;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('addresses.fields.type'))
                    ->options(AddressType::class)
                    ->default(AddressType::Main->value)
                    ->required()
                    ->native(false),

                TextInput::make('street')
                    ->label(__('addresses.fields.street'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('number')
                    ->label(__('addresses.fields.number'))
                    ->maxLength(20),

                TextInput::make('complement')
                    ->label(__('addresses.fields.complement'))
                    ->maxLength(100),

                TextInput::make('neighborhood')
                    ->label(__('addresses.fields.neighborhood'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('city')
                    ->label(__('addresses.fields.city'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('state')
                    ->label(__('addresses.fields.state'))
                    ->required()
                    ->maxLength(2),

                TextInput::make('zip_code')
                    ->label(__('addresses.fields.zip_code'))
                    ->required()
                    ->maxLength(10),

                TextInput::make('country')
                    ->label(__('addresses.fields.country'))
                    ->default('BR')
                    ->required()
                    ->maxLength(2),

                Toggle::make('is_default')
                    ->label(__('common.fields.is_default'))
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('street')
            ->columns([
                TextColumn::make('type')
                    ->label(__('addresses.fields.type'))
                    ->badge(),

                TextColumn::make('street')
                    ->label(__('addresses.fields.street'))
                    ->searchable(),

                TextColumn::make('city')
                    ->label(__('addresses.fields.city'))
                    ->searchable(),

                TextColumn::make('state')
                    ->label(__('addresses.fields.state')),

                IconColumn::make('is_default')
                    ->label(__('common.fields.is_default'))
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScopes([SoftDeletingScope::class]));
    }
}
