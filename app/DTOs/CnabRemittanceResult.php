<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CnabRemittanceResult
{
    /**
     * @param  array<string, array{batch_number: int, record_sequence: int, payment_form_code: string}>  $placements  keyed by settlement item id
     */
    public function __construct(
        public string $content,
        public int $recordsCount,
        public int $batchesCount,
        public array $placements,
        public string $totalAmount,
    ) {}
}
