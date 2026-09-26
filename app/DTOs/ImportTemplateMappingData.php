<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ImportTargetField;

final readonly class ImportTemplateMappingData
{
    public function __construct(
        public ImportTargetField $targetField,
        public ?string $sourceColumn = null,
        public ?string $defaultValue = null,
        public int $sortOrder = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $targetField = $data['target_field'] ?? $data['targetField'] ?? null;
        $targetField = $targetField instanceof ImportTargetField
            ? $targetField
            : ImportTargetField::from((string) $targetField);

        return new self(
            targetField: $targetField,
            sourceColumn: isset($data['source_column']) || isset($data['sourceColumn'])
                ? self::nullableString($data['source_column'] ?? $data['sourceColumn'] ?? null)
                : null,
            defaultValue: isset($data['default_value']) || isset($data['defaultValue'])
                ? self::nullableString($data['default_value'] ?? $data['defaultValue'] ?? null)
                : null,
            sortOrder: (int) ($data['sort_order'] ?? $data['sortOrder'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_column' => $this->sourceColumn,
            'target_field' => $this->targetField->value,
            'default_value' => $this->defaultValue,
            'sort_order' => $this->sortOrder,
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
