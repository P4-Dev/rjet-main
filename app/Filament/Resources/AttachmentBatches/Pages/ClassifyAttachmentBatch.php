<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttachmentBatches\Pages;

use App\Filament\Resources\AttachmentBatches\Actions\ConcludeAttachmentBatchClassificationAction;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Livewire\Attachment\ClassifyAttachmentBatch as ClassifyAttachmentBatchComponent;
use App\Models\AttachmentBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

final class ClassifyAttachmentBatch extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AttachmentBatchResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        /** @var AttachmentBatch $batch */
        $batch = $this->getRecord();

        abort_unless(
            ! $batch->trashed()
                && $batch->isPendingClassification()
                && (Filament::auth()->user()?->can('classify', $batch) ?? false),
            403,
        );
    }

    public function getTitle(): string|Htmlable
    {
        return __('attachment_batches.sections.classify');
    }

    protected function getHeaderActions(): array
    {
        return [
            ConcludeAttachmentBatchClassificationAction::make()
                ->record($this->getRecord()),
            Action::make('backToView')
                ->label(__('attachment_batches.actions.back_to_view'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => AttachmentBatchResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(ClassifyAttachmentBatchComponent::class, [
                'batchId' => (string) $this->getRecord()->getKey(),
            ])->key('classify-attachment-batch'),
        ]);
    }
}
