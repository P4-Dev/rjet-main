<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AttachmentBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('attachment_batches.sections.upload'))
                    ->columns(2)
                    ->schema([
                        FileUpload::make('files')
                            ->label(__('attachment_batches.fields.files'))
                            ->helperText(__('attachment_batches.hints.upload'))
                            ->multiple()
                            ->disk(fn (): string => (string) config('rjet.attachments.disk'))
                            ->visibility('private')
                            ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
                            ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
                            ->maxFiles(fn (): int => (int) config('rjet.attachments.max_files'))
                            ->storeFiles(false)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
