<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\RelationManagers;

use App\Enums\AttachmentType;
use App\Filament\Resources\PaymentRequests\Actions\DownloadAttachmentAction;
use App\Filament\Resources\PaymentRequests\Actions\ExtractBoletoOcrAction;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\AttachmentService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('common.sections.attachments');
    }

    public function canCreate(): bool
    {
        return $this->canManageOwnerAttachments();
    }

    public function canReorder(): bool
    {
        return $this->canManageOwnerAttachments();
    }

    private function canManageOwnerAttachments(): bool
    {
        $user = Filament::auth()->user();
        $owner = $this->getOwnerRecord();

        return $user !== null
            && $owner instanceof PaymentRequest
            && $user->can('manageAttachments', $owner);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('upload')
                    ->label(__('attachments.fields.file'))
                    ->disk(fn (): string => (string) config('rjet.attachments.disk'))
                    ->directory(fn (): string => app(AttachmentService::class)->stagingDirectoryFor(Filament::auth()->user()))
                    ->visibility('private')
                    ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
                    ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
                    ->required()
                    ->storeFiles(false)
                    ->columnSpanFull(),

                Select::make('type')
                    ->label(__('attachments.fields.type'))
                    ->options(AttachmentType::class)
                    ->native(false)
                    ->default(AttachmentType::Other->value),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('original_name')
                    ->label(__('attachments.fields.original_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('attachments.fields.type'))
                    ->badge(),
                TextColumn::make('mime_type')
                    ->label(__('attachments.fields.mime_type'))
                    ->toggleable(),
                TextColumn::make('size')
                    ->label(__('attachments.fields.size'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 0, ',', '.').' KB')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('attachments.fields.type'))
                    ->options(AttachmentType::class),
                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, RelationManager $livewire): Attachment {
                        /** @var TemporaryUploadedFile|UploadedFile $file */
                        $file = $data['upload'];
                        $type = filled($data['type'] ?? null)
                            ? AttachmentType::from((string) $data['type'])
                            : AttachmentType::Other;

                        return app(AttachmentService::class)->storeUploadedFile(
                            $livewire->getOwnerRecord(),
                            $file,
                            $type,
                        );
                    }),
            ])
            ->recordActions([
                DownloadAttachmentAction::make(),
                ExtractBoletoOcrAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
