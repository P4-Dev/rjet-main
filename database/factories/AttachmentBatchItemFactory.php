<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttachmentBatchItem>
 */
final class AttachmentBatchItemFactory extends Factory
{
    protected $model = AttachmentBatchItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachment_batch_id' => AttachmentBatch::factory(),
            'attachment_id' => fn (array $attributes): string => (string) Attachment::factory()
                ->forBatch(AttachmentBatch::query()->findOrFail($attributes['attachment_batch_id']))
                ->create()
                ->getKey(),
            'sort_order' => 0,
            'status' => AttachmentBatchItemStatus::Pending,
            'destination_type' => null,
            'payment_request_id' => null,
            'supplier_id' => null,
            'operational_label' => null,
            'classified_by' => null,
            'classified_at' => null,
            'rename_error' => null,
            'renamed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => AttachmentBatchItemStatus::Pending]);
    }

    public function classified(): static
    {
        return $this->operationalCategory();
    }

    public function forPaymentRequest(?PaymentRequest $paymentRequest = null): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchItemStatus::Classified,
            'destination_type' => AttachmentBatchDestinationType::PaymentRequest,
            'payment_request_id' => $paymentRequest?->getKey() ?? PaymentRequest::factory(),
            'supplier_id' => null,
            'operational_label' => null,
            'classified_at' => now(),
        ]);
    }

    public function forSupplier(?Supplier $supplier = null): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchItemStatus::Classified,
            'destination_type' => AttachmentBatchDestinationType::Supplier,
            'payment_request_id' => null,
            'supplier_id' => $supplier?->getKey() ?? Supplier::factory(),
            'operational_label' => null,
            'classified_at' => now(),
        ]);
    }

    public function operationalCategory(string $label = 'Despesas gerais'): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchItemStatus::Classified,
            'destination_type' => AttachmentBatchDestinationType::OperationalCategory,
            'payment_request_id' => null,
            'supplier_id' => null,
            'operational_label' => $label,
            'classified_at' => now(),
        ]);
    }

    public function renamed(): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchItemStatus::Renamed,
            'renamed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Storage move failed.'): static
    {
        return $this->state(fn (): array => [
            'status' => AttachmentBatchItemStatus::Failed,
            'rename_error' => $error,
        ]);
    }
}
