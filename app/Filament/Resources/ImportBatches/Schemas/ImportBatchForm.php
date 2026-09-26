<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Models\ImportTemplateVersion;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('import_batches.sections.upload'))
                    ->columns(2)
                    ->schema([
                        Select::make('import_template_version_id')
                            ->label(__('import_batches.fields.import_template_version_id'))
                            ->options(fn (): array => ImportTemplateVersion::query()
                                ->where('is_current', true)
                                ->whereNull('deleted_at')
                                ->whereHas('template', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
                                ->with('template')
                                ->get()
                                ->mapWithKeys(fn ($v) => [
                                    $v->id => sprintf(
                                        '%s (v%d) · %s',
                                        $v->template->name,
                                        $v->version,
                                        $v->template->accepted_format->getLabel(),
                                    ),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        FileUpload::make('spreadsheet')
                            ->label(__('import_batches.fields.spreadsheet'))
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->maxSize((int) config('rjet.imports.max_kilobytes'))
                            ->storeFiles(false)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
