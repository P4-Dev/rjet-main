<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AttachmentBatchDestinationType;
use App\Exceptions\AttachmentException;

final readonly class AttachmentBatchItemClassificationData
{
    public function __construct(
        public AttachmentBatchDestinationType $destinationType,
        public ?string $paymentRequestId = null,
        public ?string $supplierId = null,
        public ?string $operationalLabel = null,
        public ?int $sortOrder = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AttachmentException
     */
    public static function fromArray(array $data): self
    {
        $destination = $data['destination_type'] ?? null;

        if (! $destination instanceof AttachmentBatchDestinationType) {
            $destination = AttachmentBatchDestinationType::tryFrom((string) $destination);
        }

        if ($destination === null) {
            throw AttachmentException::invalidDestination();
        }

        return new self(
            destinationType: $destination,
            paymentRequestId: filled($data['payment_request_id'] ?? null) ? (string) $data['payment_request_id'] : null,
            supplierId: filled($data['supplier_id'] ?? null) ? (string) $data['supplier_id'] : null,
            operationalLabel: filled($data['operational_label'] ?? null) ? trim((string) $data['operational_label']) : null,
            sortOrder: isset($data['sort_order']) ? (int) $data['sort_order'] : null,
        );
    }

    /**
     * @return array{destination_type: AttachmentBatchDestinationType, payment_request_id: ?string, supplier_id: ?string, operational_label: ?string}
     */
    public function toModelAttributes(): array
    {
        return [
            'destination_type' => $this->destinationType,
            'payment_request_id' => $this->paymentRequestId,
            'supplier_id' => $this->supplierId,
            'operational_label' => $this->operationalLabel,
        ];
    }
}
