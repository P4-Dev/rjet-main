<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Enums\ContactType;
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

final class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('contacts.fields.type'))
                    ->options(ContactType::class)
                    ->default(ContactType::Main->value)
                    ->required()
                    ->native(false),

                TextInput::make('name')
                    ->label(__('contacts.fields.name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label(__('contacts.fields.email'))
                    ->email()
                    ->maxLength(255),

                TextInput::make('phone')
                    ->label(__('contacts.fields.phone'))
                    ->maxLength(20),

                TextInput::make('mobile')
                    ->label(__('contacts.fields.mobile'))
                    ->maxLength(20),

                TextInput::make('position')
                    ->label(__('contacts.fields.position'))
                    ->maxLength(100),

                TextInput::make('department')
                    ->label(__('contacts.fields.department'))
                    ->maxLength(100),

                Toggle::make('is_default')
                    ->label(__('common.fields.is_default'))
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('type')
                    ->label(__('contacts.fields.type'))
                    ->badge(),

                TextColumn::make('name')
                    ->label(__('contacts.fields.name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('contacts.fields.email'))
                    ->searchable(),

                TextColumn::make('phone')
                    ->label(__('contacts.fields.phone')),

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
