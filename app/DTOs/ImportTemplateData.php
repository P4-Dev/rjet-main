<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ImportFileFormat;

final readonly class ImportTemplateData
{
    /**
     * @param  list<ImportTemplateMappingData>  $mappings
     */
    public function __construct(
        public string $name,
        public ImportFileFormat $acceptedFormat,
        public bool $isActive = true,
        public ?string $companyId = null,
        public ?string $branchId = null,
        public array $mappings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $format = $data['accepted_format'] ?? $data['acceptedFormat'] ?? null;
        $format = $format instanceof ImportFileFormat
            ? $format
            : ImportFileFormat::from((string) $format);

        $mappings = [];
        foreach ($data['mappings'] ?? [] as $index => $mapping) {
            if ($mapping instanceof ImportTemplateMappingData) {
                $mappings[] = $mapping;

                continue;
            }

            $row = (array) $mapping;
            if (! isset($row['sort_order']) && ! isset($row['sortOrder'])) {
                $row['sort_order'] = $index;
            }
            $mappings[] = ImportTemplateMappingData::fromArray($row);
        }

        return new self(
            name: (string) $data['name'],
            acceptedFormat: $format,
            isActive: (bool) ($data['is_active'] ?? $data['isActive'] ?? true),
            companyId: self::nullableString($data['company_id'] ?? $data['companyId'] ?? null),
            branchId: self::nullableString($data['branch_id'] ?? $data['branchId'] ?? null),
            mappings: $mappings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'accepted_format' => $this->acceptedFormat->value,
            'is_active' => $this->isActive,
            'mappings' => array_map(
                static fn (ImportTemplateMappingData $m): array => $m->toArray(),
                $this->mappings,
            ),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
