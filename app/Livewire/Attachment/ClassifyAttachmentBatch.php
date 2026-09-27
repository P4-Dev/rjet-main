<?php

declare(strict_types=1);

namespace App\Livewire\Attachment;

use App\Actions\Attachment\ClassifyAttachmentBatchItemAction;
use App\Actions\Attachment\ReorderAttachmentBatchItemsAction;
use App\DTOs\AttachmentBatchItemClassificationData;
use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\AttachmentBatches\AttachmentBatchResource;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AttachmentBatchClassificationService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ClassifyAttachmentBatch extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    private const SEARCH_RESULTS_LIMIT = 50;

    #[Locked]
    public string $batchId;

    public ?string $selectedItemId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(string $batchId): void
    {
        $this->batchId = $batchId;

        abort_unless($this->actor()->can('classify', $this->batch), 403);

        if (! $this->batch->isPendingClassification()) {
            $this->redirectToView();

            return;
        }

        $firstItem = $this->batch->items->firstWhere('status', AttachmentBatchItemStatus::Pending)
            ?? $this->batch->items->first();

        if ($firstItem !== null) {
            $this->selectItem((string) $firstItem->getKey());
        } else {
            $this->form->fill();
        }
    }

    #[Computed]
    public function batch(): AttachmentBatch
    {
        return AttachmentBatch::query()
            ->with(['items.attachment', 'items.paymentRequest.supplier', 'items.supplier'])
            ->findOrFail($this->batchId);
    }

    #[Computed]
    public function selectedItem(): ?AttachmentBatchItem
    {
        if ($this->selectedItemId === null) {
            return null;
        }

        return $this->batch->items->first(
            fn (AttachmentBatchItem $item): bool => (string) $item->getKey() === $this->selectedItemId,
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('destination_type')
                    ->label(__('attachment_batches.fields.destination_type'))
                    ->options(AttachmentBatchDestinationType::class)
                    ->live()
                    ->required()
                    ->native(false)
                    ->afterStateUpdated(function (Set $set): void {
                        $set('payment_request_id', null);
                        $set('supplier_id', null);
                        $set('operational_label', null);
                    }),

                Select::make('payment_request_id')
                    ->label(__('attachment_batches.fields.payment_request_id'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchPaymentRequests($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->paymentRequestLabel($value))
                    ->visible(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::PaymentRequest))
                    ->required(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::PaymentRequest))
                    ->native(false),

                Select::make('supplier_id')
                    ->label(__('attachment_batches.fields.supplier_id'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSuppliers($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Supplier::query()->whereKey($value)->value('name'))
                    ->visible(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::Supplier))
                    ->required(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::Supplier))
                    ->native(false),

                TextInput::make('operational_label')
                    ->label(__('attachment_batches.fields.operational_label'))
                    ->helperText(__('attachment_batches.hints.operational_label'))
                    ->maxLength(AttachmentBatchClassificationService::OPERATIONAL_LABEL_MAX_LENGTH)
                    ->visible(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::OperationalCategory))
                    ->required(fn (Get $get): bool => $this->destinationIs($get, AttachmentBatchDestinationType::OperationalCategory)),
            ]);
    }

    public function selectItem(string $itemId): void
    {
        $item = $this->batch->items->first(
            fn (AttachmentBatchItem $candidate): bool => (string) $candidate->getKey() === $itemId,
        );

        if ($item === null) {
            return;
        }

        $this->selectedItemId = $itemId;
        unset($this->selectedItem);

        $this->form->fill([
            'destination_type' => $item->destination_type?->value,
            'payment_request_id' => $item->payment_request_id,
            'supplier_id' => $item->supplier_id,
            'operational_label' => $item->operational_label,
        ]);
    }

    public function save(): void
    {
        $item = $this->selectedItem;

        if ($item === null) {
            return;
        }

        $state = $this->form->getState();

        try {
            app(ClassifyAttachmentBatchItemAction::class)(
                $item,
                AttachmentBatchItemClassificationData::fromArray($state),
                $this->actor(),
            );
        } catch (BusinessException $e) {
            $this->handleBusinessException($e);

            return;
        }

        Notification::make()
            ->title(__('attachment_batches.messages.classification_saved'))
            ->success()
            ->send();

        $this->refreshBatch();
        $this->selectNextPendingItem();
    }

    /**
     * Handler for `wire:sort`: receives the dragged item key and its new 0-based position.
     */
    public function sortItem(string $itemId, int $position): void
    {
        $orderedIds = $this->batch->items
            ->map(fn (AttachmentBatchItem $item): string => (string) $item->getKey())
            ->reject(fn (string $id): bool => $id === $itemId)
            ->values()
            ->all();

        array_splice($orderedIds, max(0, $position), 0, [$itemId]);

        try {
            app(ReorderAttachmentBatchItemsAction::class)($this->batch, $orderedIds, $this->actor());
        } catch (BusinessException $e) {
            $this->handleBusinessException($e);

            return;
        }

        $this->refreshBatch();

        Notification::make()
            ->title(__('attachment_batches.messages.reordered'))
            ->success()
            ->send();
    }

    public function downloadSelected(): ?StreamedResponse
    {
        $attachment = $this->selectedItem?->attachment;

        abort_unless($attachment !== null && $this->actor()->can('view', $attachment), 403);

        try {
            return Storage::disk($attachment->disk)->download($attachment->path, $attachment->displayName());
        } catch (Throwable) {
            Notification::make()
                ->title(__('attachments.errors.file_not_found'))
                ->danger()
                ->send();

            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.attachment.classify-attachment-batch');
    }

    private function selectNextPendingItem(): void
    {
        $items = $this->batch->items->values();
        $currentIndex = $items->search(
            fn (AttachmentBatchItem $item): bool => (string) $item->getKey() === $this->selectedItemId,
        );

        $next = $items
            ->slice($currentIndex === false ? 0 : $currentIndex + 1)
            ->merge($items)
            ->first(fn (AttachmentBatchItem $item): bool => $item->status === AttachmentBatchItemStatus::Pending);

        if ($next !== null) {
            $this->selectItem((string) $next->getKey());
        }
    }

    private function handleBusinessException(BusinessException $e): void
    {
        Notification::make()
            ->title($e->getUserMessage())
            ->danger()
            ->send();

        $this->refreshBatch();

        if (! $this->batch->isPendingClassification()) {
            $this->redirectToView();
        }
    }

    private function refreshBatch(): void
    {
        unset($this->batch, $this->selectedItem);
    }

    private function redirectToView(): void
    {
        Notification::make()
            ->title(__('attachments.errors.batch_not_classifiable'))
            ->warning()
            ->send();

        $this->redirect(AttachmentBatchResource::getUrl('view', ['record' => $this->batchId]));
    }

    private function destinationIs(Get $get, AttachmentBatchDestinationType $expected): bool
    {
        $value = $get('destination_type');

        if ($value instanceof AttachmentBatchDestinationType) {
            return $value === $expected;
        }

        return AttachmentBatchDestinationType::tryFrom((string) $value) === $expected;
    }

    /**
     * @return array<string, string>
     */
    private function searchPaymentRequests(string $search): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';

        return PaymentRequest::query()
            ->visibleTo($this->actor())
            ->with('supplier')
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(CAST(id AS TEXT)) LIKE ?', [$term])
                ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$term])))
            ->latest()
            ->limit(self::SEARCH_RESULTS_LIMIT)
            ->get()
            ->mapWithKeys(fn (PaymentRequest $paymentRequest): array => [
                (string) $paymentRequest->getKey() => $this->formatPaymentRequestLabel($paymentRequest),
            ])
            ->all();
    }

    private function paymentRequestLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $paymentRequest = PaymentRequest::query()->with('supplier')->find($value);

        return $paymentRequest !== null ? $this->formatPaymentRequestLabel($paymentRequest) : null;
    }

    private function formatPaymentRequestLabel(PaymentRequest $paymentRequest): string
    {
        return sprintf(
            '#%s · %s · R$ %s · %s',
            mb_substr((string) $paymentRequest->getKey(), 0, 8),
            $paymentRequest->supplier?->name ?? '—',
            number_format((float) $paymentRequest->net_amount, 2, ',', '.'),
            $paymentRequest->due_date?->format('d/m/Y') ?? '—',
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchSuppliers(string $search): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $documentDigits = preg_replace('/\D/', '', $search) ?? '';

        return Supplier::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->when($documentDigits !== '', fn ($documentQuery) => $documentQuery->orWhere('document', 'like', "%{$documentDigits}%")))
            ->orderBy('name')
            ->limit(self::SEARCH_RESULTS_LIMIT)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, string $id): array => [$id => $name])
            ->all();
    }

    private function actor(): User
    {
        $user = Filament::auth()->user() ?? auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
