<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportTemplates\Actions;

use App\Actions\Import\PublishImportTemplateVersionAction as PublishAction;
use App\Enums\ImportTargetField;
use App\Exceptions\BusinessException;
use App\Models\ImportTemplate;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class PublishImportTemplateVersionAction
{
    public static function make(): Action
    {
        return Action::make('publishVersion')
            ->label(__('import_templates.actions.publish_version'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(fn (?ImportTemplate $record): bool => $record !== null
                && ! $record->trashed()
                && (Filament::auth()->user()?->can('update', $record) ?? false))
            ->form([
                Repeater::make('mappings')
                    ->label(__('import_templates.fields.mappings'))
                    ->schema([
                        TextInput::make('source_column')
                            ->label(__('import_templates.fields.source_column'))
                            ->maxLength(120)
                            ->nullable(),
                        Select::make('target_field')
                            ->label(__('import_templates.fields.target_field'))
                            ->options(ImportTargetField::class)
                            ->required()
                            ->native(false),
                        TextInput::make('default_value')
                            ->label(__('import_templates.fields.default_value'))
                            ->maxLength(255)
                            ->nullable(),
                    ])
                    ->minItems(1)
                    ->default(fn (ImportTemplate $record): array => $record->currentVersion?->mappings
                        ->map(fn ($m): array => [
                            'source_column' => $m->source_column,
                            'target_field' => $m->target_field->value,
                            'default_value' => $m->default_value,
                        ])
                        ->values()
                        ->all() ?? [])
                    ->addActionLabel(__('import_templates.actions.add_mapping')),
            ])
            ->action(function (ImportTemplate $record, array $data, Action $action): void {
                try {
                    app(PublishAction::class)(
                        $record,
                        $data['mappings'] ?? [],
                        Filament::auth()->user(),
                    );

                    Notification::make()
                        ->title(__('import_templates.messages.version_published'))
                        ->success()
                        ->send();
                } catch (BusinessException $e) {
                    Notification::make()
                        ->title($e->getUserMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }
}
