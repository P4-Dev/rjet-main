<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AttachmentBatchStatus;
use App\Models\AttachmentBatch;

final readonly class AttachmentBatchData
{
    public function __construct(
        public string $id,
        public AttachmentBatchStatus $status,
        public int $itemsCount,
    ) {}

    public static function fromModel(AttachmentBatch $batch): self
    {
        return new self(
            id: (string) $batch->getKey(),
            status: $batch->status,
            itemsCount: (int) $batch->items_count,
        );
    }

    /**
     * @return array{id: string, status: string, items_count: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'items_count' => $this->itemsCount,
        ];
    }
}
