<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ImportRowData
{
    /**
     * @param  array<string, mixed>  $cells
     * @param  array<string, mixed>  $resolved
     */
    public function __construct(
        public int $rowNumber,
        public array $cells,
        public array $resolved = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rowNumber: (int) ($data['row_number'] ?? $data['rowNumber'] ?? 0),
            cells: (array) ($data['cells'] ?? []),
            resolved: (array) ($data['resolved'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'cells' => $this->cells,
            'resolved' => $this->resolved,
        ];
    }
}
